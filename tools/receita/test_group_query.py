import unittest

from group_query import normalize_root


class NormalizeRootTest(unittest.TestCase):
    def test_numeric_root(self):
        self.assertEqual(
            normalize_root(
                "11.222.333/0001-81"
            ),
            "11222333",
        )

    def test_numeric_root_directly(self):
        self.assertEqual(
            normalize_root(
                "11222333"
            ),
            "11222333",
        )

    def test_alphanumeric_full_cnpj(self):
        self.assertEqual(
            normalize_root(
                "00.000.000/E08G-12"
            ),
            "00000000",
        )

    def test_alphanumeric_root(self):
        self.assertEqual(
            normalize_root(
                "AB12CD34"
            ),
            "AB12CD34",
        )

    def test_lowercase_is_normalized(self):
        self.assertEqual(
            normalize_root(
                "ab12cd34"
            ),
            "AB12CD34",
        )

    def test_invalid_root_length(self):
        with self.assertRaises(
            ValueError
        ):
            normalize_root(
                "123"
            )


if __name__ == "__main__":
    unittest.main()
