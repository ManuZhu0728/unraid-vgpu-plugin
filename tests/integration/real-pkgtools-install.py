#!/usr/bin/env python3
"""Opt-in plugin Installer.boot test, reusing one driver's private pkgtools root.

Requires the driver repo's tests/integration/real_pkgtools_lifecycle.py and an
exact retained work/pkgtools-lifecycle.* directory. Never creates a second
large root, installs into the host, invokes a GPU, or calls Runtime.ensure.
"""

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import time


REPO = Path(__file__).resolve().parents[2]


def source_provenance(repo=REPO):
    """Record actual runtime bytes, including uncommitted/untracked source."""
    def git(*arguments):
        return subprocess.run(["git", "-C", str(repo), *arguments], check=True, capture_output=True, text=True).stdout.strip()

    files, links = {}, {}
    for path in sorted((repo / "source").rglob("*")):
        name = path.relative_to(repo).as_posix()
        if path.is_symlink():
            links[name] = os.readlink(path)
        elif path.is_file():
            with path.open("rb") as stream:
                files[name] = hashlib.file_digest(stream, "sha256").hexdigest()
    if not files:
        raise RuntimeError("production source inventory is empty")
    tests = {}
    for name in ("tests/integration/install-driver.php", "tests/integration/real-pkgtools-install.py", "tests/test_real_install_harness.py"):
        path = repo / name
        if path.is_file():
            with path.open("rb") as stream:
                tests[name] = hashlib.file_digest(stream, "sha256").hexdigest()
    return {"git_head": git("rev-parse", "HEAD"),
            "git_status_source": git("status", "--porcelain=v1", "--untracked-files=all", "--", "source").splitlines(),
            "source_sha256": files, "source_symlinks": links, "test_harness_sha256": tests,
            "scope": "local integration provenance of actual worktree bytes, not a release checksum attachment"}


def verify_snapshot_backups(harness, receipt, expected):
    """Journal metadata/hashes must describe every original fixture exactly."""
    records = receipt["configurations"]
    for name, before in expected.items():
        record = records.get("/" + name)
        harness.require(record is not None, "protected fixture missing from transaction: " + name)
        harness.require(record["kind"] == {"symlink": "link"}.get(before["kind"], before["kind"]), "backup type differs: " + name)
        for key in ("mode", "uid", "gid"):
            harness.require(record[key] == before[key], "backup metadata differs: " + name)
        if before["kind"] == "file":
            harness.require(record["sha256"] == before["sha256"], "backup content differs: " + name)
        elif before["kind"] == "symlink":
            harness.require(record["target"] == before["target"], "backup link differs: " + name)
    return len(expected)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--driver-repo", type=Path, default=REPO.parent / "unraid-vgpu")
    parser.add_argument("--work", type=Path, required=True, help="exact retained pkgtools-lifecycle.* artifact directory")
    parser.add_argument("--new-package", type=Path, required=True)
    parser.add_argument("--old-package", type=Path, help="actual archive to seed an upgrade scenario; never modified or renamed")
    parser.add_argument("--scenario", choices=("fresh", "reinstall", "upgrade"), required=True)
    parser.add_argument("--fault-after-upgrade", choices=("none", "failure", "timeout"), default="none",
                        help="explicitly inject a separate real PHP child exit/timeout only after genuine pkgtools succeeds; upgrade scenarios only")
    parser.add_argument("--php", default=os.environ.get("PHP_BIN"))
    args = parser.parse_args(argv)
    path = args.driver_repo.resolve() / "tests/integration/real_pkgtools_lifecycle.py"
    spec = importlib.util.spec_from_file_location("driver_real_pkgtools", path)
    harness = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(harness)
    harness.require(args.php and Path(args.php).is_file(), "--php or PHP_BIN required")
    harness.require((args.scenario == "upgrade") == bool(args.old_package), "--old-package is required only for upgrade")
    harness.require(args.fault_after_upgrade == "none" or args.scenario == "upgrade", "--fault-after-upgrade requires an upgrade scenario")
    work = args.work.absolute()
    sandbox = harness.PkgtoolsSandbox(work, args.driver_repo.resolve() / "inputs/packages/pkgtools-15.0-noarch-42.txz",
                                    args.php, 900, resume=True)
    harness.require(shutil.disk_usage(work).free >= 4 * 1024 ** 3, "at least 4 GiB free required before real package/cache operations")
    tools = sandbox.prepare_host_tools()
    label = "plugin-" + args.scenario + "-" + ("fault-" + args.fault_after_upgrade + "-" if args.fault_after_upgrade != "none" else "") + str(time.time_ns())
    cases = work / "plugin-cases"
    cases.mkdir(exist_ok=True)
    artifacts = cases / label
    artifacts.mkdir()
    summary = {"status": "running", "scenario": args.scenario, "fault_after_upgrade": args.fault_after_upgrade,
               "artifacts": str(artifacts), "test_root": str(sandbox.root),
               "scope": "production plugin boot installation, genuine pkgtools/passive tools, fixture ROOT; not Unraid or GPU runtime evidence"}
    production = None
    try:
        production = source_provenance()
        harness.write_json(artifacts / "production-source-before.json", production)
        summary["production_source_before"] = str(artifacts / "production-source-before.json")
        summary["production_git_head"] = production["git_head"]
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
                if args.fault_after_upgrade != "none":
                    owned = (database / old.stem).read_text().split("FILE LIST:\n", 1)[1].splitlines()
                    for name in harness.CONFIGS[:2]:
                        harness.require(name in owned or "./" + name in owned, "fault scenario needs an actual legacy-owned bare config: " + name)
        else:
            harness.require(active == [package.stem], "reinstall must start with exactly the selected new package installed")
        names, expected = harness.configure_user_settings(sandbox.root, pending_runtime=args.scenario == "reinstall")
        docker_before = json.loads((sandbox.root / harness.CONFIGS[0]).read_text())
        watched = set(names) | {name + ".new" for name in harness.CONFIGS} | set(harness.EXTRA_CONFIGS)
        absent_before = sorted(name for name in watched if not (sandbox.root / name).exists() and not (sandbox.root / name).is_symlink())
        harness.write_json(artifacts / "configurations-before.json", expected)
        harness.write_json(artifacts / "configurations-absent-before.json", absent_before)
        sandbox.run(label + "-boot", [args.php, "-n", REPO / "tests/integration/install-driver.php", package,
                                      sandbox.root, tools, artifacts, "--fault-after-upgrade", args.fault_after_upgrade],
                    env={"VGPU_REAL_PKGTOOLS_ISOLATED": "1"})
        response = json.loads((artifacts / "result.json").read_text())
        harness.require(response["ok"], "plugin boot adapter did not complete")
        harness.verify_payload(sandbox.root, package, modules, links)
        transaction = json.loads(Path(response["transaction"]).read_text())
        backup_count = verify_snapshot_backups(harness, transaction, expected)
        configuration_restore = "verified"
        if args.fault_after_upgrade == "timeout":
            # Completion-unknown must not race a potentially running installer
            # with restoration. Record current state, but verify originals in
            # the private transaction backups instead of claiming restoration.
            actual = {}
            for name in names:
                path = sandbox.root / name
                actual.update(harness.snapshot(sandbox.root, [name]) if path.exists() or path.is_symlink() else {name: {"kind": "absent"}})
            docker = sandbox.root / harness.CONFIGS[0]
            harness.require(not docker.exists() and not docker.is_symlink(), "completion-unknown unexpectedly restored the genuinely removed Docker file")
            configuration_restore = "not_attempted_completion_unknown"
        else:
            actual = harness.snapshot(sandbox.root, names)
            docker_after = json.loads((sandbox.root / harness.CONFIGS[0]).read_text())
            if args.fault_after_upgrade == "none":
                runtime = docker_after["runtimes"].pop("nvidia")
                harness.require(runtime["path"].endswith("nvidia-container-runtime"), "real nvidia-ctk did not register runtime")
                # Docker formatting/size/hash may change, but permissions and
                # non-NVIDIA JSON settings must remain exactly as before.
                for key in ("bytes", "sha256"):
                    expected[harness.CONFIGS[0]][key] = actual[harness.CONFIGS[0]][key]
            else:
                for name in absent_before:
                    path = sandbox.root / name
                    harness.require(not path.exists() and not path.is_symlink(), "failure did not restore original absence: " + name)
            harness.require(docker_after == docker_before, "unrelated Docker configuration changed")
            harness.require(actual == expected, "protected configuration bytes, mode, uid, gid or symlink target changed")
        harness.write_json(artifacts / "configurations-after.json", actual)
        if args.fault_after_upgrade == "none" and args.scenario != "reinstall":
            for name, content in templates.items():
                if name not in expected:
                    harness.require((sandbox.root / name).read_bytes() == content, "new vendor template missing: " + name)
        upgrade = [command for command in response["commands"] if command["requested"][0] == "upgradepkg"]
        harness.require(len(upgrade) == 1 and upgrade[0]["executed"][0] == "/sbin/upgradepkg" and upgrade[0]["code"] == 0,
                        "missing successful genuine pkgtools execution")
        upgrade_log = (artifacts / upgrade[0]["stdout_log"]).read_text()
        if args.scenario == "upgrade":
            harness.require(upgrade[0]["requested"][3].startswith(old.stem + "%"), "plugin did not explicitly select old pkgtools record")
            harness.require("Upgrading " + old.stem in upgrade_log and "Deleting " + str(sandbox.root / harness.CONFIGS[0]) in upgrade_log,
                            "no real legacy upgrade/removal evidence")
            harness.require(not (database / old.stem).exists(), "legacy package record remains active")
        if args.fault_after_upgrade != "none":
            injected = response["injection"]
            harness.require(injected["genuine_upgrade_returncode"] == 0 and injected["response"]["code"] ==
                            {"failure": 9, "timeout": 124}[args.fault_after_upgrade] and injected["child_reaped"] is True,
                            "fault did not come from the distinct, reaped child after successful pkgtools")
            harness.require(response["installer"]["status"] == "failed" and response["same_boot_retry"]["status"] == "already_attempted" and
                            response["same_boot_retry_command_count_unchanged"] is True, "same-boot failure gate was not verified")
            harness.require(transaction["status"] == {"failure": "failed_restored", "timeout": "completion_unknown"}[args.fault_after_upgrade],
                            "fault transaction classification differs")
            summary["fault_scope"] = "separate controlled PHP child after genuine pkgtools success; not an observed driver/pkgtools/GPU fault"
            summary["package_payload_rollback_claimed"] = False
        summary.update(status="passed", module_hashes_verified=len(modules), library_symlinks_verified=len(links),
                       protected_configurations_verified=0 if args.fault_after_upgrade == "timeout" else len(names),
                       configuration_backups_verified=backup_count, configuration_restore=configuration_restore,
                       docker_other_settings_preserved=None if args.fault_after_upgrade == "timeout" else True,
                       result=str(artifacts / "result.json"), command_log=str(artifacts / "commands.json"))
    except Exception as error:
        summary.update(status="failed", error=str(error))
    try:
        after = source_provenance()
        harness.write_json(artifacts / "production-source-after.json", after)
        summary["production_source_after"] = str(artifacts / "production-source-after.json")
        harness.require(production is not None and all(after[key] == production[key] for key in
                        ("git_head", "source_sha256", "source_symlinks", "test_harness_sha256")),
                        "production source, HEAD or test harness changed during the case")
        summary["production_source_unchanged"] = True
    except Exception as error:
        summary.update(status="failed", provenance_error=str(error))
    harness.write_json(artifacts / "summary.json", summary)
    if summary["status"] != "passed":
        print("FAIL " + summary.get("error", summary.get("provenance_error", "unknown")) + "; artifacts=" + str(artifacts), flush=True)
        return 1
    print("PASS plugin " + args.scenario + "; summary=" + str(artifacts / "summary.json"), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
