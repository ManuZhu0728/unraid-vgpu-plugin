#!/usr/bin/env python3
"""Build a local/offline preview and, optionally, a public URL-based installer.

This only archives plugin files. It never installs packages, contacts GitLab,
reads credentials or runs the Unraid backend.
"""

import argparse
import base64
import hashlib
import io
from pathlib import Path
import re
import tarfile
from urllib.parse import urlsplit
from xml.sax.saxutils import escape, quoteattr

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "unraid-vgpu-manager"
PREFIX = Path("usr/local/emhttp/plugins") / PLUGIN


def https_url(value):
    parsed = urlsplit(value)
    if parsed.scheme != "https" or not parsed.netloc or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise argparse.ArgumentTypeError("Use a public HTTPS URL without credentials, query or fragment")
    return value.rstrip("/")


def definition(version, filename, md5, *, inline=None, public_base=None, update_url=None):
    attributes = f'name="{PLUGIN}" author="unraid-vgpu-manager contributors" version={quoteattr(version)} launch="Settings/{PLUGIN}" min="7.0.0"'
    if update_url:
        attributes += f" pluginURL={quoteattr(update_url)}"
    if inline is not None:
        payload = f'<INLINE>{base64.b64encode(inline).decode()}</INLINE>'
        encoding = ' Type="base64"'
    else:
        payload = f"<URL>{escape(public_base + '/' + filename)}</URL>"
        encoding = ""
    return f'''<?xml version="1.0" standalone="yes"?>
<PLUGIN {attributes}>
<CHANGES>
Automatic GitHub/GitLab source detection and kernel-matched driver downloads.
Local TXZ import with chunked uploads and optional MD5 verification.
Simplified settings and status page.
Unraid upgrade preparation and verified boot-time driver installation.
Fix form and upload validation after Unraid consumes the native CSRF field.
</CHANGES>
<FILE Name="/boot/config/plugins/{PLUGIN}/{filename}" Run="upgradepkg --install-new --reinstall"{encoding}>
{payload}
<MD5>{md5}</MD5>
</FILE>
<FILE Run="/bin/bash"><INLINE><![CDATA[
# Installation must succeed without a repository, token or NVIDIA package.
# The new plugin must not take ownership of an existing GPU stack implicitly.
if ! /usr/local/emhttp/plugins/{PLUGIN}/scripts/rc.vgpu boot </dev/null; then
  echo "{PLUGIN}: UI installed; runtime needs configuration or maintenance. Existing services were not taken over."
fi
/bin/bash /usr/local/emhttp/plugins/{PLUGIN}/scripts/upgrade-helper start
]]></INLINE></FILE>
<FILE Run="/bin/bash" Method="remove"><INLINE><![CDATA[
# Do not kill GPU clients, undefine mdevs or remove driver packages on uninstall.
if [ -f /usr/local/emhttp/plugins/{PLUGIN}/scripts/upgrade-helper ]; then
  /bin/bash /usr/local/emhttp/plugins/{PLUGIN}/scripts/upgrade-helper stop
fi
if pgrep -x nvidia-vgpu-mgr >/dev/null 2>&1; then
  echo "The NVIDIA manager remains running. Plan a maintenance reboot before using another runtime owner."
fi
removepkg {PLUGIN}
echo "Persistent settings, credentials, device definitions and cached drivers were retained."
]]></INLINE></FILE>
</PLUGIN>
'''


def build(version, output, public_base=None, update_url=None):
    if not re.fullmatch(r"\d{4}\.\d{2}\.\d{2}\.\d+", version):
        raise ValueError("Plugin version must be YYYY.MM.DD.N")
    source = ROOT / "source" / PREFIX
    required = ["unraid-vgpu-manager.page", "scripts/rc.vgpu", "scripts/upgrade-helper", "include/cli.php", "include/Store.php", "include/Runtime.php", "include/Download.php", "include/Source.php", "include/Upload.php", "include/Upgrade.php", "include/Installer.php", "include/upgrade-watch.php"]
    for name in required:
        if not (source / name).is_file():
            raise ValueError(f"Missing component: {name}")
    if bool(public_base) != bool(update_url):
        raise ValueError("Set both --public-base and --update-url for a public installer")
    output.mkdir(parents=True, exist_ok=True)
    filename = f"{PLUGIN}-{version}-noarch-1.txz"
    package = output / filename
    if package.exists():
        raise ValueError("Output already exists; use a new version or a different output directory")
    with tarfile.open(package, "w:xz", format=tarfile.PAX_FORMAT) as archive:
        for path in sorted(source.rglob("*")):
            if path.is_symlink():
                raise ValueError(f"Refusing source symlink: {path}")
            if not path.is_file():
                continue
            relative = path.relative_to(source)
            if any(part.startswith(".") for part in relative.parts) or path.suffix in {".token", ".pyc"} or path.name == "credentials.json":
                raise ValueError(f"Private or generated file in payload: {relative}")
            data = path.read_bytes()
            member = tarfile.TarInfo((PREFIX / relative).as_posix())
            member.uid = member.gid = 0
            member.uname = member.gname = "root"
            member.mode = 0o755 if relative.parts[0] == "scripts" else 0o644
            member.mtime = 0
            member.size = len(data)
            archive.addfile(member, io.BytesIO(data))
    data = package.read_bytes()
    md5 = hashlib.md5(data).hexdigest()
    (output / (filename + ".md5")).write_text(f"{md5}  {filename}\n")
    # Keep the installed definition's canonical basename, otherwise a later
    # online installer could leave a second boot definition behind.
    offline = output / 'offline' / f"{PLUGIN}.plg"
    offline.parent.mkdir(parents=True, exist_ok=True)
    offline.write_text(definition(version, filename, md5, inline=data))
    if public_base:
        (output / f"{PLUGIN}.plg").write_text(definition(version, filename, md5, public_base=public_base, update_url=update_url))
    print(f"Plugin package: {package}")
    print(f"Offline installer: {offline}")
    print("Plugin build complete.")
    return package, offline


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", default="2026.11.02.6")
    parser.add_argument("--output", type=Path)
    parser.add_argument("--public-base", type=https_url)
    parser.add_argument("--update-url", type=https_url)
    args = parser.parse_args()
    try:
        build(args.version, args.output or ROOT / 'dist' / args.version, args.public_base, args.update_url)
    except (ValueError, OSError) as error:
        parser.exit(1, f"Build failed: {error}\n")


if __name__ == "__main__":
    main()
