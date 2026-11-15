"""Cheap CLI/isolation guard checks; never install a TXZ or invoke pkgtools."""

import contextlib
import copy
import importlib.util
import io
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock


REPO = Path(__file__).resolve().parents[1]
ADAPTER = REPO / "tests/integration/install-driver.php"
ORCHESTRATOR = REPO / "tests/integration/real-pkgtools-install.py"
SPEC = importlib.util.spec_from_file_location("real_install_harness", ORCHESTRATOR)
HARNESS = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HARNESS)


class RealInstallHarnessCliTests(unittest.TestCase):
    def rejected_selection(self, scenario, old_package=False, fault="none", expected="--old-package is required only for upgrade"):
        # This stub tests only the orchestrator's argument boundary. It must
        # reject the request before a sandbox or any installer can be created.
        def require(condition, message):
            if not condition:
                raise RuntimeError(message)

        fake = SimpleNamespace(require=require, PkgtoolsSandbox=mock.Mock(
            side_effect=AssertionError("sandbox must not be initialized")))
        fake_spec = SimpleNamespace(loader=SimpleNamespace(exec_module=lambda module: None))
        with tempfile.TemporaryDirectory(prefix="vgpu-install-cli-guard-") as temporary:
            args = ["--work", temporary, "--new-package", "never-opened.txz", "--scenario", scenario, "--php", sys.executable]
            args += ["--fault-after-upgrade", fault]
            if old_package:
                args += ["--old-package", "never-opened-old.txz"]
            with mock.patch.object(HARNESS.importlib.util, "spec_from_file_location", return_value=fake_spec), \
                    mock.patch.object(HARNESS.importlib.util, "module_from_spec", return_value=fake):
                with self.assertRaisesRegex(RuntimeError, expected):
                    HARNESS.main(args)
            fake.PkgtoolsSandbox.assert_not_called()
            self.assertEqual(list(Path(temporary).iterdir()), [])

    def test_upgrade_requires_old_package_before_sandbox_initialization(self):
        self.rejected_selection("upgrade")

    def test_nonupgrade_rejects_old_package_before_sandbox_initialization(self):
        for scenario in ("fresh", "reinstall"):
            with self.subTest(scenario=scenario):
                self.rejected_selection(scenario, old_package=True)

    def test_help_does_not_import_driver_harness_or_create_a_root(self):
        output = io.StringIO()
        with mock.patch.object(HARNESS.importlib.util, "spec_from_file_location") as loader, \
                contextlib.redirect_stdout(output), self.assertRaises(SystemExit) as exit_status:
            HARNESS.main(["--help"])
        self.assertEqual(exit_status.exception.code, 0)
        loader.assert_not_called()
        self.assertIn("--scenario", output.getvalue())
        self.assertIn("--fault-after-upgrade", output.getvalue())

    def test_fault_requires_upgrade_before_sandbox_initialization(self):
        for scenario in ("fresh", "reinstall"):
            for fault in ("failure", "timeout"):
                with self.subTest(scenario=scenario, fault=fault):
                    self.rejected_selection(scenario, fault=fault, expected="--fault-after-upgrade requires an upgrade scenario")

    def test_invalid_fault_choice_rejected_before_driver_import(self):
        output = io.StringIO()
        with mock.patch.object(HARNESS.importlib.util, "spec_from_file_location") as loader, \
                contextlib.redirect_stderr(output), self.assertRaises(SystemExit) as exit_status:
            HARNESS.main(["--work", "/never-opened", "--new-package", "never-opened.txz", "--scenario", "upgrade",
                          "--old-package", "never-opened-old.txz", "--fault-after-upgrade", "invalid"])
        self.assertEqual(exit_status.exception.code, 2)
        loader.assert_not_called()
        self.assertIn("invalid choice", output.getvalue())


class RealInstallSnapshotVerificationTests(unittest.TestCase):
    def setUp(self):
        def require(condition, message):
            if not condition:
                raise RuntimeError(message)

        self.harness = SimpleNamespace(require=require)
        self.expected = {
            "etc/config": {"kind": "file", "mode": 0o640, "uid": 1000, "gid": 1000, "sha256": "f" * 64},
            "etc/link": {"kind": "symlink", "mode": 0o777, "uid": 1000, "gid": 1000, "target": "/boot/config"},
            "etc/tokens": {"kind": "directory", "mode": 0o700, "uid": 1000, "gid": 1000},
        }
        self.receipt = {"configurations": {"/" + name: dict(value, kind="link" if value["kind"] == "symlink" else value["kind"])
                                            for name, value in self.expected.items()}}

    def test_all_original_metadata_hashes_and_links_are_required_without_mutation(self):
        original = copy.deepcopy(self.expected)
        self.assertEqual(HARNESS.verify_snapshot_backups(self.harness, self.receipt, self.expected), 3)
        self.assertEqual(self.expected, original)

    def test_missing_or_changed_backup_records_are_rejected(self):
        for name, key, value in (("/etc/config", "sha256", "0" * 64), ("/etc/config", "mode", 0o644),
                                 ("/etc/link", "target", "/wrong"), ("/etc/tokens", "kind", "file")):
            with self.subTest(name=name, key=key):
                receipt = copy.deepcopy(self.receipt)
                receipt["configurations"][name][key] = value
                with self.assertRaises(RuntimeError):
                    HARNESS.verify_snapshot_backups(self.harness, receipt, self.expected)
        del self.receipt["configurations"]["/etc/config"]
        with self.assertRaisesRegex(RuntimeError, "missing from transaction"):
            HARNESS.verify_snapshot_backups(self.harness, self.receipt, self.expected)

    def test_provenance_records_actual_dirty_source_bytes_and_git_head(self):
        with tempfile.TemporaryDirectory(prefix="vgpu-source-provenance-") as temporary:
            repo = Path(temporary)
            source = repo / "source/include"
            source.mkdir(parents=True)
            (source / "StartupInventory.php").write_bytes(b"uncommitted production fixture\n")
            responses = [SimpleNamespace(stdout="f" * 40 + "\n"),
                         SimpleNamespace(stdout="?? source/include/StartupInventory.php\n")]
            with mock.patch.object(HARNESS.subprocess, "run", side_effect=responses):
                result = HARNESS.source_provenance(repo)
            self.assertEqual(result["git_head"], "f" * 40)
            self.assertEqual(result["source_sha256"], {"source/include/StartupInventory.php": hashlib.sha256(b"uncommitted production fixture\n").hexdigest()})
            self.assertEqual(result["git_status_source"], ["?? source/include/StartupInventory.php"])


class RealInstallAdapterGuardTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = os.environ.get("PHP_BIN") or shutil.which("php") or str(REPO / ".tools/php/root/usr/bin/php8.4")
        if not Path(php).is_file():
            raise unittest.SkipTest("PHP CLI unavailable")
        cls.php = php

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="pkgtools-lifecycle.guard-")
        self.addCleanup(self.temporary.cleanup)
        self.work = Path(self.temporary.name)
        self.root = self.work / "root"
        self.root.mkdir()
        # An invalid invocation must never overwrite even an existing result
        # in an unvalidated, user-supplied artifact directory.
        self.outside = self.work / "not-validated-artifacts"
        self.outside.mkdir()
        self.sentinel = self.outside / "result.json"
        self.sentinel.write_bytes(b"existing artifact must be preserved\n")

    def run_adapter(self, root, *, marked=False, fault=None):
        env = {key: value for key, value in os.environ.items() if key not in ("ROOT", "VGPU_REAL_PKGTOOLS_ISOLATED")}
        env["ROOT"] = str(root)
        if marked:
            env["VGPU_REAL_PKGTOOLS_ISOLATED"] = "1"
        arguments = [self.php, "-n", str(ADAPTER), str(self.work / "never-opened.txz"),
                     str(root), str(self.work / "never-opened-tools"), str(self.outside)]
        if fault is not None:
            arguments += ["--fault-after-upgrade", fault]
        result = subprocess.run(arguments,
                                capture_output=True, text=True, env=env, timeout=10)
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertEqual(self.sentinel.read_bytes(), b"existing artifact must be preserved\n")
        self.assertEqual(list(self.root.iterdir()), [], "guard failure wrote installation fixtures")
        return result

    def test_missing_isolation_marker_fails_before_tool_execution_or_writes(self):
        result = self.run_adapter(self.root)
        self.assertIn("Only run through the read-only-host bubblewrap integration harness", result.stderr)

    def test_broad_root_fails_without_writing_unvalidated_artifacts(self):
        result = self.run_adapter(Path("/"), marked=True)
        self.assertIn("An exact private lifecycle ROOT is required", result.stderr)

    def test_symlink_root_fails_without_writing_target_or_artifacts(self):
        linked = self.work / "linked-root"
        linked.symlink_to(self.root, target_is_directory=True)
        result = self.run_adapter(linked, marked=True)
        self.assertIn("An exact private lifecycle ROOT is required", result.stderr)

    def test_invalid_fault_rejected_before_root_tools_or_artifact_writes(self):
        result = self.run_adapter(self.root, fault="invalid")
        self.assertIn("Invalid --fault-after-upgrade", result.stderr)

    def test_valid_fault_mode_still_requires_isolation_before_child_execution(self):
        for mode in ("failure", "timeout"):
            with self.subTest(mode=mode):
                result = self.run_adapter(self.root, fault=mode)
                self.assertIn("Only run through the read-only-host bubblewrap integration harness", result.stderr)
                self.assertEqual(list(self.outside.iterdir()), [self.sentinel])


if __name__ == "__main__":
    unittest.main()
