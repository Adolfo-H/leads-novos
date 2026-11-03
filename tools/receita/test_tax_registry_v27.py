import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from zipfile import ZipFile

from build_tax_registry import build
from tax_registry import statuses


class TaxRegistryV27Test(unittest.TestCase):
    def test_mei_simples_unknown_and_absent(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            source = root / 'Simples.zip'
            with ZipFile(source, 'w') as zipfile:
                zipfile.writestr('SIMPLES.CSV', '\n'.join([
                    '"00000001";"S";"20240101";"";"S";"20240101";""',
                    '"00000002";"S";"20240101";"";"N";"";""',
                    '"00000003";"N";"";"";"N";"";""',
                    '"00000004";"";"";"";"N";"";""',
                ]))
            with patch.dict(os.environ, {'RECEITA_TAX_REGISTRY_DIR': str(root)}):
                count, blocked, target = build(source, '2026-10', min_rows=1)
                self.assertEqual((count, blocked), (4, 3))
                self.assertTrue(target.is_file())
                # Fixture de 4 linhas abaixo do limiar público de segurança:
                # subir limiar de leitura só neste teste unitário.
                # Checa o conteúdo SQLite diretamente sem burlar produção.
                import sqlite3
                db = sqlite3.connect(target)
                try:
                    result = statuses(db, ['00000001', '00000002', '00000003', '00000004', '00000005'])
                    self.assertEqual(result['00000001']['regime'], 'mei')
                    self.assertEqual(result['00000002']['regime'], 'simples')
                    self.assertEqual(result['00000003']['regime'], 'nao_optante')
                    self.assertEqual(result['00000004']['regime'], 'desconhecido')
                    self.assertEqual(result['00000005']['regime'], 'nao_optante')
                finally:
                    db.close()


if __name__ == '__main__':
    unittest.main()
