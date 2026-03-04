import os
import json
import requests
from typing import Optional, Dict, Any

class DirectusClient:
    def __init__(self):
        self.base = os.getenv("VP_DIRECTUS_INTERNAL_URL", "http://directus:8055").rstrip("/")
        self.token = os.getenv("VP_DIRECTUS_TOKEN", "").strip()
        if not self.token:
            raise RuntimeError("VP_DIRECTUS_TOKEN is empty")

    def _headers(self) -> Dict[str, str]:
        return {"Authorization": f"Bearer {self.token}"}

    def get_job(self, job_id: str) -> Dict[str, Any]:
        r = requests.get(f"{self.base}/items/vp_3d_jobs/{job_id}", headers=self._headers(), timeout=30)
        r.raise_for_status()
        return r.json()["data"]

    def patch_job(self, job_id: str, payload: Dict[str, Any]) -> None:
        r = requests.patch(
            f"{self.base}/items/vp_3d_jobs/{job_id}",
            headers={**self._headers(), "Content-Type": "application/json"},
            data=json.dumps(payload),
            timeout=30,
        )
        r.raise_for_status()

    def download_file_to(self, file_id: str, out_path: str) -> None:
        # /assets/{id} gives raw file
        url = f"{self.base}/assets/{file_id}"
        with requests.get(url, headers=self._headers(), stream=True, timeout=120) as r:
            r.raise_for_status()
            with open(out_path, "wb") as f:
                for chunk in r.iter_content(chunk_size=1024 * 1024):
                    if chunk:
                        f.write(chunk)

    def upload_file(self, file_path: str, title: Optional[str] = None, mime: Optional[str] = None) -> str:
        files = {"file": open(file_path, "rb")}
        data = {}
        if title:
            data["title"] = title
        # Directus сам определит mime; если надо — добавим позже через metadata
        r = requests.post(f"{self.base}/files", headers=self._headers(), files=files, data=data, timeout=300)
        r.raise_for_status()
        return r.json()["data"]["id"]
