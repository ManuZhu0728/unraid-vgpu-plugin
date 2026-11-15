#!/usr/bin/env python3
"""Opt-in plugin Installer.boot test, reusing one driver's private pkgtools root.

Requires the driver repo's tests/integration/real_pkgtools_lifecycle.py and an
exact retained work/pkgtools-lifecycle.* directory. Never creates a second
large root, installs into the host, invokes a GPU, or calls Runtime.ensure.
"""

import argparse
import importlib.util
import json
import os
from pathlib import Path
import shutil
import time


REPO = Path(__file__).resolve().parents[2]


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--driver-repo", type=Path, default=REPO.parent / "unraid-vgpu")
    parser.add_argument("--work", type=Path, required=True, help="exact retained pkgtools-lifecycle.* artifact directory")
    parser.add_argument("--new-package", type=Path, required=True)
    parser.add_argument("--old-package", type=Path, help="actual archive to seed an upgrade scenario; never modified or renamed")
    parser.add_argument("--scenario", choices=("fresh", "reinstall", "upgrade"), required=True)
    parser.add_argument("--php", default=os.environ.get("PHP_BIN"))
    args = parser.parse_args(argv)
    path = args.driver_repo.resolve() / "tests/integration/real_pkgtools_lifecycle.py"
    spec = importlib.util.spec_from_file_location("driver_real_pkgtools", path)
    harness = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(harness)
    harness.require(args.php and Path(args.php).is_file(), "--php or PHP_BIN required")
    harness.require((args.scenario == "upgrade") == bool(args.old_package), "--old-package is required only for upgrade")
    work = args.work.absolute()
    sandbox = harness.PkgtoolsSandbox(work, args.driver_repo.resolve() / "inputs/packages/pkgtools-15.0-noarch-42.txz",
                                    args.php, 900, resume=True)
    harness.require(shutil.disk_usage(work).free >= 4 * 1024 ** 3, "at least 4 GiB free required before real package/cache operations")
    tools = sandbox.prepare_host_tools()
    label = "plugin-" + args.scenario + "-" + str(time.time_ns())
    cases = work / "plugin-cases"
    cases.mkdir(exist_ok=True)
    artifacts = cases / label
    artifacts.mkdir()
    summary = {"status": "running", "scenario": args.scenario, "artifacts": str(artifacts), "test_root": str(sandbox.root),
               "scope": "production plugin boot installation, genuine pkgtools/passive tools, fixture ROOT; not Unraid or GPU runtime evidence"}
    try:
        package = args.new_package.absolute()
        summary["new_package"] = harness.archive_identity(package)
        print("SCAN new archive (one streaming pass)", flush=True)
        modules, templates, links = harness.package_inventory(package, summary["new_package"]["kernel"])
        database = sandbox.root / "var/lib/pkgtools/packages"
        active = sorted(path.name for path in database.glob("nvidia-*") if "-upgraded-" not in path.name)
        if args.scenario in ("fresh", "upgrade"):
            for index, name in enumerate(active):
                sandbox.run(label + f"-remove-{index}-for-root-reuse", ["/sbin/removepkg", name])
            if args.old_package:
                old = args.old_package.absolute()
                summary["old_package"] = harness.archive_identity(old)
                harness.require(summary["old_package"]["kernel"] == summary["new_package"]["kernel"], "old/new kernels must match")
                sandbox.run(label + "-install-old", ["/sbin/installpkg", old])
        else:
            harness.require(active == [package.stem], "reinstall must start with exactly the selected new package installed")
        names, expected = harness.configure_user_settings(sandbox.root, pending_runtime=args.scenario == "reinstall")
        docker_before = json.loads((sandbox.root / harness.CONFIGS[0]).read_text())
        harness.write_json(artifacts / "configurations-before.json", expected)
        sandbox.run(label + "-boot", [args.php, "-n", REPO / "tests/integration/install-driver.php", package,
                                      sandbox.root, tools, artifacts], env={"VGPU_REAL_PKGTOOLS_ISOLATED": "1"})
        response = json.loads((artifacts / "result.json").read_text())
        harness.require(response["ok"], "plugin boot adapter did not complete")
        harness.verify_payload(sandbox.root, package, modules, links)
        actual = harness.snapshot(sandbox.root, names)
        docker_after = json.loads((sandbox.root / harness.CONFIGS[0]).read_text())
        runtime = docker_after["runtimes"].pop("nvidia")
        harness.require(runtime["path"].endswith("nvidia-container-runtime"), "real nvidia-ctk did not register runtime")
        harness.require(docker_after == docker_before, "unrelated Docker configuration changed")
        # Docker formatting/size/hash may change, but permissions and all
        # non-NVIDIA JSON settings must remain exactly as before.
        for key in ("bytes", "sha256"):
            expected[harness.CONFIGS[0]][key] = actual[harness.CONFIGS[0]][key]
        harness.require(actual == expected, "protected configuration bytes, mode, uid, gid or symlink target changed")
        harness.write_json(artifacts / "configurations-after.json", actual)
        if args.scenario != "reinstall":
            for name, content in templates.items():
                if name not in expected:
                    harness.require((sandbox.root / name).read_bytes() == content, "new vendor template missing: " + name)
        upgrade = [command for command in response["commands"] if command["requested"][0] == "upgradepkg"]
        harness.require(len(upgrade) == 1 and upgrade[0]["executed"][0] == "/sbin/upgradepkg", "missing actual pkgtools execution")
        upgrade_log = (artifacts / upgrade[0]["stdout_log"]).read_text()
        if args.scenario == "upgrade":
            harness.require(upgrade[0]["requested"][3].startswith(old.stem + "%"), "plugin did not explicitly select old pkgtools record")
            harness.require("Upgrading " + old.stem in upgrade_log and "Deleting " + str(sandbox.root / harness.CONFIGS[0]) in upgrade_log,
                            "no real legacy upgrade/removal evidence")
            harness.require(not (database / old.stem).exists(), "legacy package record remains active")
        summary.update(status="passed", module_hashes_verified=len(modules), library_symlinks_verified=len(links),
                       protected_configurations_verified=len(names), docker_other_settings_preserved=True,
                       result=str(artifacts / "result.json"), command_log=str(artifacts / "commands.json"))
    except Exception as error:
        summary.update(status="failed", error=str(error))
        harness.write_json(artifacts / "summary.json", summary)
        print("FAIL " + str(error) + "; artifacts=" + str(artifacts), flush=True)
        return 1
    harness.write_json(artifacts / "summary.json", summary)
    print("PASS plugin " + args.scenario + "; summary=" + str(artifacts / "summary.json"), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
