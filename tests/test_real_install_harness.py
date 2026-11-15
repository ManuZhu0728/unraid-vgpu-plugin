"""Cheap CLI/isolation guard checks; never install a TXZ or invoke pkgtools."""

import contextlib
import importlib.util
import io
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
    def rejected_selection(self, scenario, old_package=False):
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
            if old_package:
                args += ["--old-package", "never-opened-old.txz"]
            with mock.patch.object(HARNESS.importlib.util, "spec_from_file_location", return_value=fake_spec), \
                    mock.patch.object(HARNESS.importlib.util, "module_from_spec", return_value=fake):
                with self.assertRaisesRegex(RuntimeError, "--old-package is required only for upgrade"):
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

    def run_adapter(self, root, *, marked=False):
        env = {key: value for key, value in os.environ.items() if key not in ("ROOT", "VGPU_REAL_PKGTOOLS_ISOLATED")}
        env["ROOT"] = str(root)
        if marked:
            env["VGPU_REAL_PKGTOOLS_ISOLATED"] = "1"
        result = subprocess.run([self.php, "-n", str(ADAPTER), str(self.work / "never-opened.txz"),
                                 str(root), str(self.work / "never-opened-tools"), str(self.outside)],
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


if __name__ == "__main__":
    unittest.main()
