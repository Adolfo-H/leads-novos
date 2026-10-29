"""A18: each Receita API request reads one immutable physical dataset release."""

import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from fastapi import HTTPException

import api
from release_manifest import ReleaseValidationError


class ActiveDatasetSnapshotA18Test(unittest.TestCase):
    def setUp(self) -> None:
        self.directory = tempfile.TemporaryDirectory(prefix="receita-a18-")
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.first = self.root / "release-a"
        self.second = self.root / "release-b"
        self.first.mkdir()
        self.second.mkdir()
        self.active = self.root / "active"
        self.active.symlink_to(self.first, target_is_directory=True)
        patcher = patch.object(api, "DATA_DIR", self.active)
        patcher.start()
        self.addCleanup(patcher.stop)

    def switch_active(self) -> None:
        temporary = self.root / "pending-active"
        temporary.symlink_to(self.second, target_is_directory=True)
        os.replace(temporary, self.active)

    def test_group_uses_one_release_if_active_changes_after_validation(self) -> None:
        observed = []

        def validate(path: Path) -> dict:
            observed.append(("validate", path))
            self.assertEqual(path, self.first)
            self.switch_active()
            return {"dataset_version": "first"}

        def query(value: str, path: Path) -> dict:
            observed.append(("query", path))
            self.assertEqual(value, "AB12CD34")
            return {"dataset_version": "first", "root": value}

        with patch.object(api, "validate_release", side_effect=validate), patch.object(
            api, "query_group", side_effect=query
        ):
            result = api.get_group("AB12CD34")

        self.assertEqual(result["dataset_version"], "first")
        self.assertEqual(observed, [("validate", self.first), ("query", self.first)])
        self.assertEqual(self.active.resolve(strict=True), self.second)

    def test_subsequent_request_uses_new_active_release(self) -> None:
        with patch.object(api, "validate_release") as validate, patch.object(
            api, "query_group", return_value={}
        ) as query:
            api.get_group("AB12CD34")
            self.switch_active()
            api.get_group("AB12CD34")

        self.assertEqual(validate.call_args_list[0].args[0], self.first)
        self.assertEqual(validate.call_args_list[1].args[0], self.second)
        self.assertEqual(query.call_args_list[0].args[1], self.first)
        self.assertEqual(query.call_args_list[1].args[1], self.second)

    def test_prospects_receives_pinned_directory_and_expected_version(self) -> None:
        def query(**kwargs) -> dict:
            self.assertEqual(kwargs["data_dir"], self.first)
            self.assertEqual(kwargs["expected_dataset_version"], "a18-first")
            self.switch_active()
            return {"dataset_version": "a18-first", "count": 0, "rows_scanned": 0}

        with patch.object(api, "query_prospects", side_effect=query) as handler:
            result = api.get_prospects(dataset_version="a18-first")

        self.assertEqual(handler.call_count, 1)
        self.assertEqual(result["dataset_version"], "a18-first")
        self.assertEqual(self.active.resolve(strict=True), self.second)

    def test_health_uses_pinned_directory_for_sample_checks(self) -> None:
        manifest = {
            "mode": "fixture", "period": "2026-08", "dataset_version": "a18-first",
            "tables": {"companies": {"files": ["a"]}, "establishments": {"files": ["b"]}},
        }
        with patch.object(api, "validate_release", return_value=manifest) as validate:
            body = api.health()

        self.assertEqual(validate.call_args.args, (self.first,))
        self.assertEqual(validate.call_args.kwargs, {"verify_samples": True})
        self.assertEqual(body["dataset_version"], "a18-first")
        self.assertNotIn("data_dir", body)

    def test_missing_active_dataset_is_a_safe_503(self) -> None:
        self.active.unlink()
        for operation in (api.health, lambda: api.get_group("AB12CD34"), api.get_prospects):
            with self.subTest(operation=operation):
                with self.assertRaises(HTTPException) as captured:
                    operation()
                self.assertEqual(captured.exception.status_code, 503)
                self.assertEqual(captured.exception.detail["code"], "dataset_unavailable")
                self.assertNotIn(str(self.root), str(captured.exception.detail))

    def test_version_change_still_returns_http_409_without_querying_other_release(self) -> None:
        with patch.object(api, "query_prospects", side_effect=ReleaseValidationError("dataset_version_changed")) as query:
            with self.assertRaises(HTTPException) as captured:
                api.get_prospects(dataset_version="stale")
        self.assertEqual(captured.exception.status_code, 409)
        self.assertEqual(captured.exception.detail["code"], "dataset_version_changed")
        self.assertEqual(query.call_args.kwargs["data_dir"], self.first)


if __name__ == "__main__":
    unittest.main()
