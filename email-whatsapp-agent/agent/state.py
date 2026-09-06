"""Remember which emails were already forwarded so restarts never double-send."""

from __future__ import annotations

import json
import os
import time
from pathlib import Path


class State:
    def __init__(self, path: str | os.PathLike, retention_days: int = 30):
        self.path = Path(path)
        self.retention_seconds = retention_days * 86400
        self._processed: dict[str, float] = {}
        self._load()

    def _load(self) -> None:
        if not self.path.exists():
            return
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
        except (json.JSONDecodeError, OSError):
            data = {}
        self._processed = {str(k): float(v) for k, v in (data.get("processed") or {}).items()}
        self._prune()

    def _prune(self) -> None:
        cutoff = time.time() - self.retention_seconds
        self._processed = {k: v for k, v in self._processed.items() if v >= cutoff}

    def is_processed(self, key: str) -> bool:
        return key in self._processed

    def mark(self, key: str) -> None:
        self._processed[key] = time.time()

    def save(self) -> None:
        self._prune()
        tmp = self.path.with_suffix(self.path.suffix + ".tmp")
        tmp.write_text(json.dumps({"processed": self._processed}, indent=1), encoding="utf-8")
        os.replace(tmp, self.path)

    def __len__(self) -> int:
        return len(self._processed)
