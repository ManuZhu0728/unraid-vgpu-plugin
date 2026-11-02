import copy
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from contextlib import redirect_stdout
from unittest.mock import patch
import urllib.error


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("github_publisher", ROOT / "scripts/publish-github.py")
pub = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(pub)
BUILD_SPEC = importlib.util.spec_from_file_location("plugin_build_fixture", ROOT / "scripts/build-plugin.py")
builder = importlib.util.module_from_spec(BUILD_SPEC)
BUILD_SPEC.loader.exec_module(builder)

COMMIT = "a" * 40
RELEASE = {"version": "2026.11.02.3", "tag": "v2026.11.02.3-alpha.1", "prerelease": True}


class FakeAPI:
    repository = "owner/repo"
    prefix = "/repos/owner/repo"

    def __init__(self):
        self.target = None
        self.release = None
        self.calls = []
        self.public = {}
        self.verifications = []

    def request(self, method, path, payload=None, **options):
        self.calls.append((method, path, payload))
        if "/git/ref/tags/" in path:
            return None if self.target is None else {"object": {"type": "commit", "sha": self.target}}
        if method == "GET" and "/releases/tags/" in path:
            return copy.deepcopy(self.release)
        if method == "POST" and path == self.prefix + "/releases":
            self.target = payload["target_commitish"]
            self.release = {"id": 1, "assets": [], **copy.deepcopy(payload)}
            return copy.deepcopy(self.release)
        if method == "POST" and "/assets?name=" in path:
            name = path.split("name=", 1)[1]
            asset = {"name": name, "size": len(payload), "digest": "sha256:" + hashlib.sha256(payload).hexdigest()}
            self.release["assets"].append(asset)
            self.public[name] = payload
            return copy.deepcopy(asset)
        raise AssertionError("Unexpected API operation")

    def public_matches(self, url, local):
        self.verifications.append(url)
        if self.public[local.name] != local.read_bytes():
            raise RuntimeError("Published asset differs")


class Response(io.BytesIO):
    status = 200


class FakeOpener:
    def __init__(self, handler):
        self.handler = handler
        self.calls = []

    def open(self, request, timeout):
        self.calls.append(request)
        return self.handler(request)


class PublishTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="plugin-publish-tests-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / ".github").mkdir()
        (self.root / ".github/release.json").write_text(json.dumps(RELEASE))
        (self.root / "scripts").mkdir()
        (self.root / "scripts/build-plugin.py").write_bytes((ROOT / "scripts/build-plugin.py").read_bytes())
        source = self.root / "source/usr/local/emhttp/plugins/unraid-vgpu-manager"
        for name in ["unraid-vgpu-manager.page", "unraid-vgpu-manager-driver.page", "unraid-vgpu-manager-vgpu.page", "unraid-vgpu-manager-status.page", "include/ui-page.php", "include/ui/common.php", "include/ui/state.php", "include/ui/driver.php", "include/ui/vgpu.php", "include/ui/status.php", "include/Logs.php", "scripts/rc.vgpu", "scripts/upgrade-helper", "include/cli.php", "include/Store.php", "include/Runtime.php", "include/Download.php", "include/Source.php", "include/Upload.php", "include/Upgrade.php", "include/Installer.php", "include/upgrade-watch.php"]:
            path = source / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("passive packaging fixture\n")
        notes = self.root / "docs/releases" / (RELEASE["tag"] + ".md")
        notes.parent.mkdir(parents=True)
        notes.write_text("Alpha only. Not installed or tested on an Unraid server.\n")
        with patch.object(builder, "ROOT", self.root), redirect_stdout(io.StringIO()):
            builder.build(RELEASE["version"], self.root / "reference",
                "https://github.com/owner/repo/releases/download/" + RELEASE["tag"],
                "https://raw.githubusercontent.com/owner/repo/main/unraid-vgpu-manager.plg")
        (self.root / "unraid-vgpu-manager.plg").write_bytes((self.root / "reference/unraid-vgpu-manager.plg").read_bytes())

    def prepare(self):
        with redirect_stdout(io.StringIO()):
            return pub.prepare("owner/repo", self.root)

    def publish(self, api, release, assets, notes):
        with redirect_stdout(io.StringIO()):
            return pub.publish(api, release, assets, notes, COMMIT)

    def test_complete_build_matches_root_plg_and_only_three_public_assets(self):
        release, assets, notes = self.prepare()
        self.assertEqual(release, RELEASE)
        self.assertEqual(set(assets), {"unraid-vgpu-manager.plg", "unraid-vgpu-manager-2026.11.02.3-noarch-1.txz", "unraid-vgpu-manager-2026.11.02.3-noarch-1.txz.md5"})
        self.assertTrue((self.root / "work/release-2026.11.02.3/offline/unraid-vgpu-manager.plg").is_file())
        self.assertFalse(any("offline" in str(path.relative_to(self.root / "work/release-2026.11.02.3")) for path in assets.values()))
        api = FakeAPI()
        url = self.publish(api, release, assets, notes)
        self.assertEqual(api.target, COMMIT)
        self.assertTrue(api.release["prerelease"])
        self.assertFalse(api.release["draft"])
        self.assertEqual(api.release["make_latest"], "false")
        self.assertEqual(set(api.public), set(assets))
        self.assertEqual(len(api.verifications), 3)
        self.assertTrue(url.endswith(RELEASE["tag"]))
        self.assertFalse(any(call[0] in {"PATCH", "DELETE", "PUT"} for call in api.calls))

    def test_dry_run_needs_no_token_and_never_constructs_api(self):
        with patch.object(pub, "GitHub", side_effect=AssertionError("network")), redirect_stdout(io.StringIO()):
            self.assertEqual(pub.main(["--dry-run"], root=self.root, env={"GITHUB_REPOSITORY": "owner/repo"}), 0)

    def test_mismatching_committed_plg_fails_and_keeps_built_artifacts(self):
        (self.root / "unraid-vgpu-manager.plg").write_text("wrong content")
        with self.assertRaisesRegex(ValueError, "Root PLG differs"):
            self.prepare()
        self.assertTrue(list((self.root / "work/release-2026.11.02.3").glob("*.txz")))

    def test_existing_work_directory_is_not_clobbered(self):
        directory = self.root / "work/release-2026.11.02.3"
        directory.mkdir(parents=True)
        marker = directory / "keep-me"
        marker.write_text("first attempt evidence")
        with self.assertRaisesRegex(ValueError, "already exists"):
            self.prepare()
        self.assertEqual(marker.read_text(), "first attempt evidence")

    def test_invalid_release_metadata_and_repository_fail(self):
        for change in ({"prerelease": False}, {"tag": "v2026.11.02.3"}, {"version": "../../etc"}, {"extra": "unexpected"}):
            (self.root / ".github/release.json").write_text(json.dumps({**RELEASE, **change}))
            with self.assertRaises(ValueError):
                pub.metadata(self.root)
        for repository in ("../owner/repo", "https://example.test/owner/repo", "owner/repo?token=bad"):
            with self.assertRaises(ValueError):
                pub.prepare(repository, self.root)

    def test_existing_different_tag_is_never_moved(self):
        release, assets, notes = self.prepare()
        api = FakeAPI()
        api.target = "b" * 40
        with self.assertRaisesRegex(ValueError, "never moved"):
            self.publish(api, release, assets, notes)
        self.assertFalse(any(call[0] != "GET" for call in api.calls))

    def test_identical_existing_release_is_verified_and_reused_without_writes(self):
        release, assets, notes = self.prepare()
        api = FakeAPI()
        self.publish(api, release, assets, notes)
        api.calls = []
        self.publish(api, release, assets, notes)
        self.assertTrue(all(call[0] == "GET" for call in api.calls))
        self.assertEqual(len(api.verifications), 6)

    def test_changed_existing_asset_notes_and_unexpected_assets_are_refused(self):
        release, assets, notes = self.prepare()
        for mutation in ("asset", "notes", "extra"):
            api = FakeAPI()
            self.publish(api, release, assets, notes)
            if mutation == "asset": api.release["assets"][0]["digest"] = "sha256:" + "0" * 64
            if mutation == "notes": api.release["body"] = "changed"
            if mutation == "extra": api.release["assets"].append({"name": "offline.plg", "size": 1})
            api.calls = []
            with self.assertRaises(ValueError):
                self.publish(api, release, assets, notes)
            self.assertTrue(all(call[0] == "GET" for call in api.calls))

    def test_driver_or_offline_uploads_are_not_whitelisted(self):
        release, assets, notes = self.prepare()
        api = FakeAPI()
        assets["nvidia-driver.txz"] = next(iter(assets.values()))
        with self.assertRaisesRegex(ValueError, "three reviewed"):
            self.publish(api, release, assets, notes)
        self.assertEqual(api.calls, [])

    def test_partial_release_conflict_is_checked_before_any_new_upload(self):
        release, assets, notes = self.prepare()
        api = FakeAPI()
        self.publish(api, release, assets, notes)
        api.release["assets"] = api.release["assets"][1:]
        api.release["assets"][0]["digest"] = "sha256:" + "0" * 64
        api.calls = []
        with self.assertRaisesRegex(ValueError, "refusing clobber"):
            self.publish(api, release, assets, notes)
        self.assertTrue(all(call[0] == "GET" for call in api.calls))

    def test_authenticated_api_does_not_follow_redirects_or_log_token(self):
        token = "unit-test-token"
        def redirect(request):
            self.assertEqual(request.get_header("Authorization"), "Bearer " + token)
            self.assertNotIn(token, request.full_url)
            raise urllib.error.HTTPError(request.full_url, 302, "redirect", {"Location": "https://evil.test/?secret=" + token}, None)
        opener = FakeOpener(redirect)
        api = pub.GitHub("owner/repo", token, opener=opener)
        with self.assertRaisesRegex(RuntimeError, "HTTP 302") as caught:
            api.request("GET", api.prefix + "/releases")
        self.assertNotIn(token, str(caught.exception))
        self.assertEqual(len(opener.calls), 1)
        self.assertIsNone(pub.NoRedirect().redirect_request(None, None, 302, "", {}, "https://evil.test"))

    def test_anonymous_verification_strips_auth_and_checks_size_hash(self):
        path = self.root / "public-fixture"
        path.write_bytes(b"reviewed plugin bytes")
        def public(request):
            self.assertIsNone(request.get_header("Authorization"))
            self.assertIsNone(request.get_header("Private-token"))
            if "github.com/owner/repo/" in request.full_url:
                raise urllib.error.HTTPError(request.full_url, 302, "redirect", {"Location": "https://release-assets.githubusercontent.com/file?signature=fixture"}, None)
            return Response(path.read_bytes())
        opener = FakeOpener(public)
        api = pub.GitHub("owner/repo", "unit-test-token", public_opener=opener)
        api.public_matches("https://github.com/owner/repo/releases/download/tag/file", path)
        self.assertEqual(len(opener.calls), 2)
        bad = FakeOpener(lambda request: Response(b"wrong"))
        api.public_opener = bad
        with self.assertRaisesRegex(RuntimeError, "differs"):
            api.public_matches("https://github.com/owner/repo/releases/download/tag/file", path)

    def test_unapproved_anonymous_redirect_is_refused(self):
        path = self.root / "public-fixture"
        path.write_bytes(b"plugin")
        def redirect(request):
            raise urllib.error.HTTPError(request.full_url, 302, "redirect", {"Location": "https://evil.test/file"}, None)
        opener = FakeOpener(redirect)
        api = pub.GitHub("owner/repo", "unit-test-token", public_opener=opener)
        with self.assertRaisesRegex(RuntimeError, "approved HTTPS"):
            api.public_matches("https://github.com/owner/repo/releases/download/tag/file", path)
        self.assertEqual(len(opener.calls), 1)


if __name__ == "__main__":
    unittest.main()
