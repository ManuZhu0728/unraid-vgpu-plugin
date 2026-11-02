#!/usr/bin/env python3
"""Publish the public plugin alpha, never a NVIDIA driver or offline installer.

GitHub Actions supplies GITHUB_TOKEN. No credential files or Git credential
helpers are read. --dry-run performs the complete local build without a token.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "unraid-vgpu-manager"
REPOSITORY = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\Z")
SHA = re.compile(r"[a-fA-F0-9]{40}\Z")


def digest(path):
    with Path(path).open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


def metadata(root=ROOT):
    data = json.loads((Path(root) / ".github/release.json").read_text())
    if set(data) != {"version", "tag", "prerelease"}:
        raise ValueError("release.json must contain version, tag and prerelease only")
    version = data["version"]
    if not isinstance(version, str) or not re.fullmatch(r"[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]+", version):
        raise ValueError("Invalid plugin version")
    if not isinstance(data["tag"], str) or not re.fullmatch(re.escape("v" + version) + r"-alpha\.[1-9][0-9]*", data["tag"]):
        raise ValueError("The release tag must explicitly identify this version as alpha")
    if data["prerelease"] is not True:
        raise ValueError("Only an explicit prerelease alpha may be published")
    return data


def prepare(repository, root=ROOT, *, runner=subprocess.run):
    if not REPOSITORY.fullmatch(repository):
        raise ValueError("GITHUB_REPOSITORY must be owner/repository")
    root = Path(root)
    release = metadata(root)
    output = root / "work" / ("release-" + release["version"])
    if output.exists():
        raise ValueError("Release work directory already exists; preserve it and use a clean checkout, not clobber")
    base = f"https://github.com/{repository}/releases/download/{release['tag']}"
    update = f"https://raw.githubusercontent.com/{repository}/main/{PLUGIN}.plg"
    runner([sys.executable, str(root / "scripts/build-plugin.py"), "--version", release["version"],
            "--output", str(output), "--public-base", base, "--update-url", update], check=True, cwd=root)
    names = [f"{PLUGIN}.plg", f"{PLUGIN}-{release['version']}-noarch-1.txz"]
    names.append(names[1] + ".md5")
    assets = {name: output / name for name in names}
    for name, path in assets.items():
        if path.is_symlink() or not path.is_file() or not 0 < path.stat().st_size <= 10 * 1024 * 1024:
            raise ValueError(f"Missing, symlinked or unexpectedly large plugin artifact: {name}")
    committed = root / f"{PLUGIN}.plg"
    if committed.is_symlink() or not committed.is_file() or committed.read_bytes() != assets[names[0]].read_bytes():
        raise ValueError("Root PLG differs from the reproducibly generated public installer; commit the matching file first")
    fields = assets[names[2]].read_text().split()
    with assets[names[1]].open("rb") as stream:
        md5 = hashlib.file_digest(stream, "md5").hexdigest()
    if fields != [md5, names[1]]:
        raise ValueError("Generated TXZ/MD5 pair is inconsistent")
    notes = root / "docs/releases" / (release["tag"] + ".md")
    if notes.is_symlink() or not notes.is_file() or not notes.read_text().strip():
        raise ValueError("Committed alpha release notes are required")
    return release, assets, notes.read_text()


class GitHub:
    def __init__(self, repository, token, *, opener=None, public_opener=None):
        if not REPOSITORY.fullmatch(repository) or not token or re.search(r"[\x00-\x20\x7f]", token):
            raise ValueError("Valid repository and environment-provided GitHub token are required")
        self.repository = repository
        self.token = token
        self.prefix = "/repos/" + repository
        self.opener = opener or urllib.request.build_opener(NoRedirect())
        # Separate unauthenticated requests. Never reuse token headers here.
        self.public_opener = public_opener or urllib.request.build_opener(NoRedirect())

    def request(self, method, path, payload=None, *, missing=False, upload=False):
        if not path.startswith(self.prefix + "/") or re.search(r"[\x00-\x20\x7f]", path):
            raise ValueError("GitHub API request escaped the configured repository")
        data = json.dumps(payload).encode() if isinstance(payload, dict) else payload
        headers = {"Authorization": "Bearer " + self.token, "Accept": "application/vnd.github+json",
                   "User-Agent": PLUGIN + "-release", "X-GitHub-Api-Version": "2022-11-28"}
        headers["Content-Type"] = "application/octet-stream" if upload else "application/json"
        host = "https://uploads.github.com" if upload else "https://api.github.com"
        request = urllib.request.Request(host + path, data=data, method=method, headers=headers)
        try:
            with self.opener.open(request, timeout=90) as response:
                body = response.read(8 * 1024 * 1024 + 1)
            if len(body) > 8 * 1024 * 1024:
                raise RuntimeError("Oversized GitHub API response")
            return json.loads(body) if body else None
        except urllib.error.HTTPError as error:
            if missing and error.code == 404:
                return None
            # No response body, Location or signed URLs enter logs.
            raise RuntimeError(f"GitHub API {method}: HTTP {error.code}") from None
        except (urllib.error.URLError, json.JSONDecodeError, TimeoutError):
            raise RuntimeError("GitHub API request failed; response details omitted") from None

    def public_matches(self, url, path):
        expected_url = f"https://github.com/{self.repository}/releases/download/"
        if not url.startswith(expected_url):
            raise ValueError("Unexpected public release URL")
        target = url
        for _ in range(6):
            parsed = urllib.parse.urlsplit(target)
            hostname = (parsed.hostname or "").lower()
            if (parsed.scheme != "https" or parsed.username or parsed.password or parsed.fragment or
                    parsed.port not in (None, 443) or
                    not (hostname == "github.com" or hostname.endswith(".githubusercontent.com"))):
                raise RuntimeError("Public verification redirect left approved HTTPS download hosts")
            request = urllib.request.Request(target, headers={"User-Agent": PLUGIN + "-verify", "Accept": "application/octet-stream"})
            try:
                with self.public_opener.open(request, timeout=60) as response:
                    if response.status != 200:
                        raise RuntimeError("Unexpected public asset response")
                    total = hashlib.sha256()
                    size = 0
                    for block in iter(lambda: response.read(1024 * 1024), b""):
                        total.update(block)
                        size += len(block)
                        if size > Path(path).stat().st_size:
                            raise RuntimeError("Published asset is larger than the local artifact")
                if size != Path(path).stat().st_size or total.hexdigest() != digest(path):
                    raise RuntimeError("Published asset differs from local size/SHA256; refusing replacement")
                return
            except urllib.error.HTTPError as error:
                if error.code in (301, 302, 303, 307, 308) and error.headers.get("Location"):
                    target = urllib.parse.urljoin(target, error.headers["Location"])
                    continue
                raise RuntimeError(f"Anonymous asset verification HTTP {error.code}") from None
            except (urllib.error.URLError, TimeoutError):
                raise RuntimeError("Anonymous asset verification failed; response details omitted") from None
        raise RuntimeError("Too many public asset redirects")


def resolved_tag(api, tag):
    ref = api.request("GET", api.prefix + "/git/ref/tags/" + urllib.parse.quote(tag, safe=""), missing=True)
    if ref is None:
        return None
    target = ref.get("object", {})
    for _ in range(5):
        if target.get("type") == "commit" and SHA.fullmatch(str(target.get("sha", ""))):
            return target["sha"].lower()
        if target.get("type") != "tag" or not SHA.fullmatch(str(target.get("sha", ""))):
            break
        target = api.request("GET", api.prefix + "/git/tags/" + target["sha"]).get("object", {})
    raise ValueError("Existing release tag does not resolve to a reviewed commit")


def publish(api, release, assets, notes, commit):
    if not SHA.fullmatch(commit):
        raise ValueError("GITHUB_SHA must be a complete commit SHA")
    commit = commit.lower()
    tag = release["tag"]
    expected_names = {f"{PLUGIN}.plg", f"{PLUGIN}-{release['version']}-noarch-1.txz", f"{PLUGIN}-{release['version']}-noarch-1.txz.md5"}
    if set(assets) != expected_names or release["prerelease"] is not True:
        raise ValueError("Only the three reviewed public alpha assets may be published")
    target = resolved_tag(api, tag)
    if target is not None and target != commit:
        raise ValueError("Existing tag points to another commit; tags are never moved")
    path = api.prefix + "/releases/tags/" + urllib.parse.quote(tag, safe="")
    current = api.request("GET", path, missing=True)
    if current is not None:
        if target != commit or current.get("target_commitish") != commit or current.get("prerelease") is not True or current.get("draft"):
            raise ValueError("Existing release is not the same reviewed commit/prerelease")
        if current.get("body") != notes:
            raise ValueError("Existing release notes differ; not overwriting a published release")
    else:
        current = api.request("POST", api.prefix + "/releases", {"tag_name": tag, "target_commitish": commit,
            "name": f"Unraid vGPU Manager {tag} (alpha)", "body": notes, "draft": False, "prerelease": True,
            "make_latest": "false"})
    if not isinstance(current.get("id"), int) or current["id"] < 1:
        raise ValueError("Invalid GitHub release identifier")
    existing = {}
    for asset in current.get("assets", []):
        name = asset.get("name")
        if name not in expected_names or name in existing:
            raise ValueError("Unexpected or duplicate existing release asset; nothing was removed")
        existing[name] = asset
    base = f"https://github.com/{api.repository}/releases/download/{tag}/"
    # Check every existing asset before adding anything to a partial release.
    # A conflict late in the list must not cause an earlier missing file upload.
    for name, remote in existing.items():
        local = assets[name]
        if remote.get("size") != local.stat().st_size or remote.get("digest") not in (None, "sha256:" + digest(local)):
            raise ValueError("Existing release asset differs; refusing clobber")
        api.public_matches(base + urllib.parse.quote(name, safe=""), local)
    for name, local in assets.items():
        if name in existing:
            print("Reused verified asset:", name)
            continue
        upload_path = api.prefix + f"/releases/{current['id']}/assets?name=" + urllib.parse.quote(name, safe="")
        uploaded = api.request("POST", upload_path, local.read_bytes(), upload=True)
        if uploaded.get("name") != name or uploaded.get("size") != local.stat().st_size:
            raise RuntimeError("Uploaded asset metadata does not match the local file")
        # A small propagation delay can occur, but differing bytes never trigger clobber.
        for attempt in range(3):
            try:
                api.public_matches(base + urllib.parse.quote(name, safe=""), local)
                break
            except RuntimeError as error:
                if attempt == 2 or "HTTP 404" not in str(error):
                    raise
                time.sleep(2)
        print("Published and anonymously verified:", name)
    if resolved_tag(api, tag) != commit:
        raise RuntimeError("Published tag does not match GITHUB_SHA")
    return f"https://github.com/{api.repository}/releases/tag/{tag}"


def main(argv=None, *, root=ROOT, env=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)
    env = dict(os.environ if env is None else env)
    repository = env.get("GITHUB_REPOSITORY", "ManuZhu0728/unraid-vgpu-plugin")
    release, assets, notes = prepare(repository, root)
    print("Alpha release:", release["tag"])
    for name, path in assets.items():
        print(f"Asset: {name} ({path.stat().st_size} bytes, SHA256 {digest(path)})")
    if args.dry_run:
        print("Dry run complete: built and compared root PLG; no GitHub requests or credentials used.")
        return 0
    commit = env.get("GITHUB_SHA", "")
    if not SHA.fullmatch(commit):
        raise ValueError("A full GITHUB_SHA is required")
    head = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
    dirty = subprocess.check_output(["git", "status", "--porcelain"], cwd=root, text=True).strip()
    if head != commit or dirty:
        raise ValueError("Publish only a clean checkout of the exact GITHUB_SHA")
    api = GitHub(repository, env.get("GITHUB_TOKEN", ""))
    print(publish(api, release, assets, notes, commit))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ValueError, RuntimeError, OSError, subprocess.CalledProcessError) as error:
        raise SystemExit("Publish failed: " + str(error)) from None
