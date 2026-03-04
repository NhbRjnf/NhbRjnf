# ПРОЕКТ «ВСЁПОНЯТНО» — 3D СУПЕРМАШИНА MVP mobile-first

## Docker Compose и переменные окружения

Ниже — добавление **redis:7-alpine** и **converter (Python worker)** в существующий `docker-compose.yml`, без вмешательства в текущие Directus/WordPress/Nginx контейнеры. Конвертер общается с Directus **по внутреннему адресу сервиса** (например, `http://directus:8055`) и кладёт задачи в Redis-очередь `vp:3d:jobs`.

Directus будет использоваться так:
- **/files** для загрузки результатов (GLB + preview PNG) и чтения метаданных о входном файле. ?cite?turn3view0?turn3view1?  
- **/assets/{id}** для скачивания входного файла и (в браузере) получения output GLB / preview. Параметр `download` поддерживается. ?cite?turn3view2?  
- Авторизация — заголовок `Authorization: Bearer <STATIC_TOKEN>`, где STATIC_TOKEN создаётся в Directus Data Studio как **static token** пользователя. ?cite?turn3view3?  

### Фрагмент `docker-compose.yml` (добавить в `services:`)

```yaml
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--appendonly", "yes"]
    volumes:
      - redis_data:/data
    networks:
      - internal

  converter:
    build:
      context: ./docker/converter
    restart: unless-stopped
    depends_on:
      - redis
      - directus
    environment:
      # Directus (внутри docker network)
      DIRECTUS_URL: "http://directus:8055"
      DIRECTUS_TOKEN: "${VP_DIRECTUS_SERVICE_TOKEN}"
      DIRECTUS_TIMEOUT_SEC: "60"

      # Redis
      REDIS_URL: "redis://redis:6379/0"
      REDIS_QUEUE: "vp:3d:jobs"
      REDIS_PROCESSING: "vp:3d:jobs:processing"

      # Worker
      VP_LOG_LEVEL: "info"
      VP_WORKER_POLL_TIMEOUT_SEC: "5"
      VP_3D_MAX_ATTEMPTS: "2"

      # Runtime/logs
      VP_RUNTIME_DIR: "/opt/vseponyatno/runtime"

      # MVP budgets (mobile-first)
      VP_3D_MAX_INPUT_BYTES: "262144000"     # 250MB
      VP_3D_MAX_OUTPUT_BYTES: "104857600"    # 100MB
      VP_3D_MAX_TRIANGLES: "800000"

      # FreeCAD tessellation quality (влияет на polycount)
      VP_3D_FREECAD_LINEAR_DEFLECTION: "0.2"
      VP_3D_FREECAD_ANGULAR_DEFLECTION_DEG: "15"

      # glTF pipeline
      VP_3D_OPTIMIZE: "1"                    # обязательный optimize
      VP_3D_COMPRESS: "meshopt"              # none|draco|meshopt
      VP_3D_DRACO_METHOD: "edgebreaker"      # для режима draco
      VP_3D_MESHOPT_LEVEL: "medium"          # для режима meshopt

      # Preview
      VP_3D_PREVIEW_SIZE: "512"
      VP_3D_PREVIEW_SAMPLES: "32"

      # Headless stability
      VP_3D_USE_XVFB: "1"

      # Optional explicit tool paths
      # FREECAD_CMD: "/usr/bin/FreeCADCmd"
      # BLENDER_CMD: "/usr/bin/blender"
      # GLTF_TRANSFORM_CMD: "gltf-transform"

    volumes:
      # общий runtime, уже используемый WP (не ломаем текущий прод)
      - type: bind
        source: /opt/vseponyatno/runtime
        target: /opt/vseponyatno/runtime
    networks:
      - internal
```

### Добавить в `volumes:` (внизу compose)

```yaml
volumes:
  redis_data:
```

### Добавить в `.env` (рядом с прочими секретами)

```bash
# Directus service token (STATIC TOKEN пользователя/сервиса)
VP_DIRECTUS_SERVICE_TOKEN="__PASTE_DIRECTUS_STATIC_TOKEN__"

# Для WP endpoints (см. ниже)
VP_DIRECTUS_URL_INTERNAL="http://directus:8055"
VP_DIRECTUS_PUBLIC_URL="https://directus.your-domain.example"
VP_REDIS_HOST="redis"
VP_REDIS_PORT="6379"
VP_REDIS_QUEUE="vp:3d:jobs"
```

## Сервис converter на Python в Docker

Пайплайн сделан так, чтобы:
- STEP/IGES конвертился через FreeCAD в OBJ, затем Blender > GLB. Это соответствует практике автоматизации конвертации STEP>OBJ в headless режиме (Part.open + Mesh.export). ?cite?turn24view0?  
- GLB/GLTF/OBJ/STL обрабатывались Blender’ом напрямую. Blender запускается в headless режиме с `--background` и подключением Python-скрипта через `--python`. ?cite?turn9view0?turn10view0?  
- Оптимизация обязательна через `gltf-transform optimize`, а mesh compression (Draco/Meshopt) — опционально и ставится **последним этапом**, т.к. сжатие (особенно Meshopt) является lossy и повторное сжатие/разжатие в пайплайне ухудшает точность. ?cite?turn2view0?turn22view0?turn27view0?  

### Структура `docker/converter/`

```
docker/converter/
  Dockerfile
  requirements.txt
  worker.py
  directus.py
  queue.py
  scripts/
    freecad_export_obj.py
    blender_convert_obj_to_glb.py
    blender_render_preview.py
```

### Файл `docker/converter/Dockerfile`

```dockerfile
# docker/converter/Dockerfile
FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
WORKDIR /app

# System deps:
# - FreeCADCmd/freecadcmd for STEP/IGES -> mesh/OBJ
# - Blender for OBJ/STL/GLTF/GLB -> GLB and preview renders
# - Node.js + glTF-Transform CLI for optimization/compression
# - Xvfb to avoid headless GL/Qt issues on servers
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl git \
    python3 python3-pip python3-venv python3-dev \
    build-essential \
    xvfb \
    ffmpeg \
    freecad \
    blender \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js (LTS) for glTF-Transform CLI.
# Using NodeSource repo for a modern Node.js version.
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update && apt-get install -y --no-install-recommends nodejs \
    && npm --version && node --version \
    && npm install --global @gltf-transform/cli \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt /app/requirements.txt
RUN pip3 install --no-cache-dir -r /app/requirements.txt

COPY worker.py /app/worker.py
COPY directus.py /app/directus.py
COPY queue.py /app/queue.py
COPY scripts /app/scripts

ENV PYTHONUNBUFFERED=1

CMD ["python3", "/app/worker.py"]
```

### Файл `docker/converter/requirements.txt`

```txt
# docker/converter/requirements.txt
requests==2.32.3
redis==5.0.7
```

### Файл `docker/converter/queue.py`

```python
#!/usr/bin/env python3
from __future__ import annotations

from dataclasses import dataclass
from typing import Optional

import redis


@dataclass(frozen=True)
class QueueConfig:
    redis_url: str
    queue_key: str = "vp:3d:jobs"
    processing_key: str = "vp:3d:jobs:processing"


class RedisQueue:
    """
    Minimal Redis queue using:
      - pending list: queue_key
      - processing list: processing_key
    Reserve uses BRPOPLPUSH so jobs aren't lost if a worker dies mid-process.
    """
    def __init__(self, cfg: QueueConfig) -> None:
        self.cfg = cfg
        self.r = redis.Redis.from_url(cfg.redis_url, decode_responses=True)

    def enqueue(self, job_id: str) -> int:
        return int(self.r.lpush(self.cfg.queue_key, str(job_id)))

    def reserve(self, timeout: int = 5) -> Optional[str]:
        job_id = self.r.brpoplpush(self.cfg.queue_key, self.cfg.processing_key, timeout=timeout)
        return str(job_id) if job_id else None

    def ack(self, job_id: str) -> int:
        # remove one occurrence
        return int(self.r.lrem(self.cfg.processing_key, 1, str(job_id)))
```

### Файл `docker/converter/directus.py`

```python
#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Dict, Optional

import requests


class DirectusError(RuntimeError):
    pass


class DirectusClient:
    def __init__(self, base_url: str, token: str, timeout_sec: int = 60) -> None:
        self.base_url = base_url.rstrip("/")
        self.timeout_sec = timeout_sec
        self.session = requests.Session()
        # Static token / PAT style
        self.session.headers.update({"Authorization": f"Bearer {token}"})

    def _url(self, path: str) -> str:
        if not path.startswith("/"):
            path = "/" + path
        return self.base_url + path

    def _raise(self, r: requests.Response) -> None:
        try:
            payload = r.json()
        except Exception:
            payload = {"error": r.text[:2000]}
        raise DirectusError(f"Directus HTTP {r.status_code}: {json.dumps(payload, ensure_ascii=False)}")

    def get_item(self, collection: str, item_id: str) -> Optional[Dict[str, Any]]:
        r = self.session.get(self._url(f"/items/{collection}/{item_id}"), timeout=self.timeout_sec)
        if r.status_code == 404:
            return None
        if r.status_code >= 300:
            self._raise(r)
        return (r.json() or {}).get("data")

    def create_item(self, collection: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        r = self.session.post(self._url(f"/items/{collection}"), json=payload, timeout=self.timeout_sec)
        if r.status_code >= 300:
            self._raise(r)
        return (r.json() or {}).get("data") or {}

    def update_item(self, collection: str, item_id: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        r = self.session.patch(self._url(f"/items/{collection}/{item_id}"), json=payload, timeout=self.timeout_sec)
        if r.status_code >= 300:
            self._raise(r)
        return (r.json() or {}).get("data") or {}

    def get_file(self, file_id: str) -> Dict[str, Any]:
        r = self.session.get(self._url(f"/files/{file_id}"), timeout=self.timeout_sec)
        if r.status_code >= 300:
            self._raise(r)
        return (r.json() or {}).get("data") or {}

    def download_asset(self, file_id: str, dst_path: Path) -> None:
        dst_path.parent.mkdir(parents=True, exist_ok=True)
        # Using /assets/{id}?download=1
        with self.session.get(self._url(f"/assets/{file_id}?download=1"), stream=True, timeout=self.timeout_sec) as r:
            if r.status_code >= 300:
                self._raise(r)
            with dst_path.open("wb") as f:
                for chunk in r.iter_content(chunk_size=1024 * 1024):
                    if chunk:
                        f.write(chunk)

    def upload_file(
        self,
        src_path: Path,
        title: Optional[str] = None,
        filename_download: Optional[str] = None,
        folder: Optional[str] = None,
        mime_type: Optional[str] = None,
    ) -> str:
        """
        Upload file to Directus /files endpoint (multipart/form-data with field named 'file').
        Returns directus_files.id (uuid string).
        """
        data: Dict[str, Any] = {}
        if title:
            data["title"] = title
        if filename_download:
            data["filename_download"] = filename_download
        if folder:
            data["folder"] = folder

        file_tuple = (src_path.name, src_path.open("rb"), mime_type or "application/octet-stream")
        files = {"file": file_tuple}
        r = self.session.post(self._url("/files"), data=data, files=files, timeout=self.timeout_sec)
        if r.status_code >= 300:
            self._raise(r)
        file_id = (r.json() or {}).get("data", {}).get("id")
        if not file_id:
            raise DirectusError("Directus upload succeeded but no file id returned")
        return str(file_id)
```

### Файл `docker/converter/scripts/freecad_export_obj.py`

Этот скрипт делает STEP/IGES > OBJ через `Part.open()` и создаёт mesh через `MeshPart.meshFromShape`, затем экспортирует OBJ. Подход STEP>OBJ (Part.open + Mesh.export) типичен для headless-конвертации. ?cite?turn24view0?  

```python
#!/usr/bin/env python3
"""
FreeCAD headless export: STEP/IGES -> OBJ.

Usage (inside container):
  FreeCADCmd freecad_export_obj.py -- --input model.step --output model.obj

Notes:
- Uses Part.open() to open STEP/IGES, then Mesh.export() to export mesh as OBJ.
"""

import argparse
import math
import os
import sys

import FreeCAD
import Part
import Mesh
import MeshPart


def parse_args():
    argv = sys.argv
    if "--" in argv:
        argv = argv[argv.index("--") + 1 :]
    else:
        argv = argv[1:]

    p = argparse.ArgumentParser()
    p.add_argument("--input", required=True)
    p.add_argument("--output", required=True)
    p.add_argument("--linear-deflection", type=float, default=0.2)
    p.add_argument("--angular-deflection-deg", type=float, default=15.0)
    return p.parse_args(argv)


def main():
    args = parse_args()
    in_path = os.path.abspath(args.input)
    out_path = os.path.abspath(args.output)

    if not os.path.exists(in_path):
        raise SystemExit(f"input_not_found:{in_path}")

    Part.open(in_path)

    doc = FreeCAD.getDocument("Unnamed")
    objs = doc.findObjects()
    if not objs:
        raise SystemExit("no_objects_imported")

    mesh_objs = []
    ang = math.radians(max(0.01, args.angular_deflection_deg))

    for i, obj in enumerate(objs):
        if not hasattr(obj, "Shape"):
            continue
        shape = obj.Shape
        if shape.isNull():
            continue

        mesh = MeshPart.meshFromShape(
            Shape=shape,
            LinearDeflection=float(args.linear_deflection),
            AngularDeflection=float(ang),
            Relative=False,
        )
        mobj = doc.addObject("Mesh::Feature", f"Mesh_{i}")
        mobj.Mesh = mesh
        mesh_objs.append(mobj)

    if not mesh_objs:
        mesh_objs = [objs[0]]

    Mesh.export(mesh_objs, out_path)

    print(f"exported_obj={out_path}")
    print(f"mesh_count={len(mesh_objs)}")


if __name__ == "__main__":
    main()
```

### Файл `docker/converter/scripts/blender_convert_obj_to_glb.py`

Blender используется как CLI-конвертер в glTF/GLB, посредством `--background` и `--python`. ?cite?turn9view0?turn10view0?  

```python
#!/usr/bin/env python3
"""
Blender headless conversion: OBJ/STL/GLTF/GLB -> GLB (+ metadata JSON).

Usage:
  blender --background --factory-startup --python blender_convert_obj_to_glb.py -- \
    --input model.obj --output model.glb --meta-json meta.json --max-triangles 800000
"""

import argparse
import json
import math
import os
import sys
from typing import Any, Dict, Tuple

import bpy
from mathutils import Vector


def parse_args():
    argv = sys.argv
    if "--" in argv:
        argv = argv[argv.index("--") + 1 :]
    else:
        argv = argv[1:]

    p = argparse.ArgumentParser()
    p.add_argument("--input", required=True)
    p.add_argument("--output", required=True)
    p.add_argument("--meta-json", required=True)
    p.add_argument("--max-triangles", type=int, default=800000)
    return p.parse_args(argv)


def reset_scene():
    bpy.ops.wm.read_factory_settings(use_empty=True)


def import_model(path: str):
    ext = os.path.splitext(path)[1].lower()
    if ext == ".obj":
        bpy.ops.import_scene.obj(filepath=path)
    elif ext == ".stl":
        bpy.ops.import_mesh.stl(filepath=path)
    elif ext in (".glb", ".gltf"):
        bpy.ops.import_scene.gltf(filepath=path)
    else:
        raise RuntimeError(f"unsupported_input:{ext}")


def iter_mesh_objects():
    for obj in bpy.context.scene.objects:
        if obj.type == "MESH":
            yield obj


def apply_basic_shading():
    if not any(o.type == "LIGHT" for o in bpy.context.scene.objects):
        light_data = bpy.data.lights.new(name="KeyLight", type='SUN')
        light = bpy.data.objects.new(name="KeyLight", object_data=light_data)
        bpy.context.collection.objects.link(light)
        light.rotation_euler = (math.radians(45), math.radians(0), math.radians(45))
        light.data.energy = 2.0

    mat = bpy.data.materials.new(name="DefaultMaterial")
    mat.use_nodes = True
    for obj in iter_mesh_objects():
        if not obj.data.materials:
            obj.data.materials.append(mat)


def triangulate_meshes():
    bpy.ops.object.select_all(action="DESELECT")
    for obj in iter_mesh_objects():
        obj.select_set(True)
        bpy.context.view_layer.objects.active = obj
        mod = obj.modifiers.new(name="Triangulate", type="TRIANGULATE")
        bpy.ops.object.modifier_apply(modifier=mod.name)
        obj.select_set(False)


def compute_bbox_and_tris() -> Tuple[Dict[str, Any], int]:
    bbox_min = Vector((float("inf"), float("inf"), float("inf")))
    bbox_max = Vector((float("-inf"), float("-inf"), float("-inf")))
    tris = 0

    for obj in iter_mesh_objects():
        mesh = obj.data
        tris += len(mesh.polygons)

        for corner in obj.bound_box:
            v = obj.matrix_world @ Vector(corner)
            bbox_min.x = min(bbox_min.x, v.x)
            bbox_min.y = min(bbox_min.y, v.y)
            bbox_min.z = min(bbox_min.z, v.z)
            bbox_max.x = max(bbox_max.x, v.x)
            bbox_max.y = max(bbox_max.y, v.y)
            bbox_max.z = max(bbox_max.z, v.z)

    meta = {
        "bbox": {
            "min": [bbox_min.x, bbox_min.y, bbox_min.z],
            "max": [bbox_max.x, bbox_max.y, bbox_max.z],
        },
        "triangles": tris,
    }
    return meta, tris


def export_glb(path_out: str):
    bpy.ops.export_scene.gltf(
        filepath=path_out,
        export_format="GLB",
        export_apply=True,
        export_yup=True,
    )


def main():
    args = parse_args()
    in_path = os.path.abspath(args.input)
    out_path = os.path.abspath(args.output)
    meta_path = os.path.abspath(args.meta_json)

    reset_scene()
    import_model(in_path)

    bpy.ops.object.select_all(action="SELECT")
    bpy.ops.object.transform_apply(location=False, rotation=False, scale=True)
    bpy.ops.object.select_all(action="DESELECT")

    apply_basic_shading()
    triangulate_meshes()

    meta, tris = compute_bbox_and_tris()
    if tris > args.max_triangles:
        raise RuntimeError(f"triangles_over_limit:{tris}")

    export_glb(out_path)

    with open(meta_path, "w", encoding="utf-8") as f:
        json.dump(meta, f, ensure_ascii=False)

    print(f"exported_glb={out_path}")
    print(f"triangles={tris}")


if __name__ == "__main__":
    main()
```

### Файл `docker/converter/scripts/blender_render_preview.py`

```python
#!/usr/bin/env python3
"""
Blender headless preview render: GLB -> PNG.

Usage:
  blender --background --factory-startup --python blender_render_preview.py -- \
    --input model.glb --output preview.png --size 512 --samples 32
"""

import argparse
import math
import os
import sys
from mathutils import Vector

import bpy


def parse_args():
    argv = sys.argv
    if "--" in argv:
        argv = argv[argv.index("--") + 1 :]
    else:
        argv = argv[1:]

    p = argparse.ArgumentParser()
    p.add_argument("--input", required=True)
    p.add_argument("--output", required=True)
    p.add_argument("--size", type=int, default=512)
    p.add_argument("--samples", type=int, default=32)
    return p.parse_args(argv)


def reset_scene():
    bpy.ops.wm.read_factory_settings(use_empty=True)


def import_glb(path: str):
    bpy.ops.import_scene.gltf(filepath=path)


def compute_bbox() -> tuple[Vector, Vector]:
    bbox_min = Vector((float("inf"), float("inf"), float("inf")))
    bbox_max = Vector((float("-inf"), float("-inf"), float("-inf")))
    for obj in bpy.context.scene.objects:
        if obj.type != "MESH":
            continue
        for corner in obj.bound_box:
            v = obj.matrix_world @ Vector(corner)
            bbox_min.x = min(bbox_min.x, v.x)
            bbox_min.y = min(bbox_min.y, v.y)
            bbox_min.z = min(bbox_min.z, v.z)
            bbox_max.x = max(bbox_max.x, v.x)
            bbox_max.y = max(bbox_max.y, v.y)
            bbox_max.z = max(bbox_max.z, v.z)
    return bbox_min, bbox_max


def setup_camera_and_light():
    scene = bpy.context.scene

    light_data = bpy.data.lights.new(name="KeyLight", type='SUN')
    light = bpy.data.objects.new(name="KeyLight", object_data=light_data)
    bpy.context.collection.objects.link(light)
    light.rotation_euler = (math.radians(45), math.radians(0), math.radians(45))
    light.data.energy = 3.0

    cam_data = bpy.data.cameras.new("Camera")
    cam = bpy.data.objects.new("Camera", cam_data)
    bpy.context.collection.objects.link(cam)
    scene.camera = cam

    bbox_min, bbox_max = compute_bbox()
    center = (bbox_min + bbox_max) * 0.5
    size = (bbox_max - bbox_min)
    radius = max(size.x, size.y, size.z) * 0.65
    radius = max(radius, 0.1)

    cam.location = center + Vector((radius * 2.2, -radius * 2.2, radius * 1.6))
    direction = center - cam.location
    rot_quat = direction.to_track_quat('-Z', 'Y')
    cam.rotation_euler = rot_quat.to_euler()


def setup_render(size_px: int, samples: int, out_path: str):
    scene = bpy.context.scene
    scene.render.engine = "CYCLES"
    scene.cycles.device = "CPU"
    scene.cycles.samples = max(1, samples)
    scene.render.resolution_x = size_px
    scene.render.resolution_y = size_px
    scene.render.filepath = out_path
    scene.render.image_settings.file_format = "PNG"
    scene.render.film_transparent = True


def main():
    args = parse_args()
    in_path = os.path.abspath(args.input)
    out_path = os.path.abspath(args.output)

    reset_scene()
    import_glb(in_path)

    bpy.ops.object.select_all(action="SELECT")
    bpy.ops.object.transform_apply(location=False, rotation=False, scale=True)
    bpy.ops.object.select_all(action="DESELECT")

    setup_camera_and_light()
    setup_render(args.size, args.samples, out_path)

    bpy.ops.render.render(write_still=True)
    print(f"rendered_png={out_path}")


if __name__ == "__main__":
    main()
```

### Файл `docker/converter/worker.py`

Ключевые детали:
- Очередь Redis реализована через `BRPOPLPUSH` > “at-least-once” доставка.
- Preview PNG загружается **раньше**, чем финальная оптимизация/сжатие > progressive UX.
- `gltf-transform optimize` обязателен, а `draco/meshopt` опционален и выполняется последним, что соответствует рекомендациям (lossy compress — финальная стадия). ?cite?turn2view0?turn22view0?turn27view0?  

```python
#!/usr/bin/env python3
"""
VP 3D Converter Worker

Pipeline (MVP):
Directus Files input -> (FreeCAD for STEP/IGES -> OBJ) -> Blender -> GLB -> glTF-Transform optimize
-> (optional) Draco/Meshopt -> Blender preview -> upload outputs -> update vp_3d_jobs.

Design goals:
- Mobile-first: enforce triangle & size budgets.
- Stable: uses Redis queue with a processing list (BRPOPLPUSH) for at-least-once delivery.
- Safe logs: never log tokens/cookies, never dump binary blobs.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import tempfile
import time
import traceback
import zipfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Optional, Tuple

from directus import DirectusClient
from queue import QueueConfig, RedisQueue


def _env(name: str, default: Optional[str] = None) -> str:
    v = os.environ.get(name)
    return v if v is not None and v != "" else (default if default is not None else "")

def _env_int(name: str, default: int) -> int:
    v = _env(name, None)
    if v is None or v == "":
        return default
    try:
        return int(v)
    except ValueError:
        return default

def _env_bool(name: str, default: bool = False) -> bool:
    v = _env(name, None)
    if v is None or v == "":
        return default
    return v.lower() in ("1", "true", "yes", "y", "on")

@dataclass(frozen=True)
class Config:
    directus_url: str
    directus_token: str
    directus_timeout_sec: int

    redis_url: str
    redis_queue: str
    redis_processing: str

    log_level: str
    runtime_dir: str

    freecad_cmd: Optional[str]
    blender_cmd: Optional[str]
    gltf_transform_cmd: str
    use_xvfb: bool

    max_input_bytes: int
    max_output_bytes: int
    max_triangles: int

    freecad_linear_deflection: float
    freecad_angular_deflection_deg: float

    do_optimize: bool
    compress_mode: str  # none|draco|meshopt
    draco_method: str
    meshopt_level: str

    preview_size: int
    preview_samples: int

    poll_timeout_sec: int
    max_attempts: int
    tmp_root: str

    @staticmethod
    def from_env() -> "Config":
        directus_url = _env("DIRECTUS_URL", "http://directus:8055").rstrip("/")
        directus_token = _env("DIRECTUS_TOKEN", "")
        if not directus_token:
            raise SystemExit("DIRECTUS_TOKEN is required")

        return Config(
            directus_url=directus_url,
            directus_token=directus_token,
            directus_timeout_sec=_env_int("DIRECTUS_TIMEOUT_SEC", 60),

            redis_url=_env("REDIS_URL", "redis://redis:6379/0"),
            redis_queue=_env("REDIS_QUEUE", "vp:3d:jobs"),
            redis_processing=_env("REDIS_PROCESSING", "vp:3d:jobs:processing"),

            log_level=_env("VP_LOG_LEVEL", "info").lower(),
            runtime_dir=_env("VP_RUNTIME_DIR", "/opt/vseponyatno/runtime"),

            freecad_cmd=_env("FREECAD_CMD", "").strip() or None,
            blender_cmd=_env("BLENDER_CMD", "").strip() or None,
            gltf_transform_cmd=_env("GLTF_TRANSFORM_CMD", "gltf-transform"),
            use_xvfb=_env_bool("VP_3D_USE_XVFB", True),

            max_input_bytes=_env_int("VP_3D_MAX_INPUT_BYTES", 250 * 1024 * 1024),
            max_output_bytes=_env_int("VP_3D_MAX_OUTPUT_BYTES", 100 * 1024 * 1024),
            max_triangles=_env_int("VP_3D_MAX_TRIANGLES", 800_000),

            freecad_linear_deflection=float(_env("VP_3D_FREECAD_LINEAR_DEFLECTION", "0.2")),
            freecad_angular_deflection_deg=float(_env("VP_3D_FREECAD_ANGULAR_DEFLECTION_DEG", "15")),

            do_optimize=_env_bool("VP_3D_OPTIMIZE", True),
            compress_mode=_env("VP_3D_COMPRESS", "none").lower(),
            draco_method=_env("VP_3D_DRACO_METHOD", "edgebreaker").lower(),
            meshopt_level=_env("VP_3D_MESHOPT_LEVEL", "medium").lower(),

            preview_size=_env_int("VP_3D_PREVIEW_SIZE", 512),
            preview_samples=_env_int("VP_3D_PREVIEW_SAMPLES", 32),

            poll_timeout_sec=_env_int("VP_WORKER_POLL_TIMEOUT_SEC", 5),
            max_attempts=_env_int("VP_3D_MAX_ATTEMPTS", 2),
            tmp_root=_env("VP_3D_TMP_ROOT", "/tmp/vp3d"),
        )

def log(level: str, msg: str, **fields: Any) -> None:
    levels = ["debug", "info", "warning", "error"]
    cfg_level = os.environ.get("VP_LOG_LEVEL", "info").lower()
    if levels.index(level) < levels.index(cfg_level):
        return
    payload = {"level": level, "msg": msg, "ts": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
    for k, v in fields.items():
        if k.lower() in ("token", "access_token", "refresh_token", "cookie", "authorization", "password"):
            continue
        payload[k] = v
    print(json.dumps(payload, ensure_ascii=False), flush=True)

def which_any(candidates: Tuple[str, ...]) -> Optional[str]:
    for c in candidates:
        p = shutil.which(c)
        if p:
            return p
    return None

def safe_filename(name: str) -> str:
    name = name.replace("\\", "/")
    name = name.split("/")[-1]
    name = "".join(ch for ch in name if ch.isalnum() or ch in ("-", "_", ".", " "))
    return name[:200] or "file"

def run_cmd(args: list[str], timeout_sec: int = 3600) -> Tuple[int, str]:
    log("debug", "Run cmd", cmd=" ".join(args))
    try:
        proc = subprocess.run(
            args,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            timeout=timeout_sec,
            check=False,
        )
        out = proc.stdout or ""
        if len(out) > 20000:
            out = out[:20000] + "\n...[truncated]..."
        return proc.returncode, out
    except subprocess.TimeoutExpired as e:
        out = (e.stdout or "") + "\nTIMEOUT"
        return 124, out

def unzip_safely(zip_path: Path, dst_dir: Path) -> None:
    with zipfile.ZipFile(zip_path, "r") as zf:
        for info in zf.infolist():
            name = info.filename.replace("\\", "/")
            if name.startswith("/") or ".." in name.split("/"):
                continue
            zf.extract(info, dst_dir)

def pick_input_from_dir(root: Path) -> Optional[Path]:
    exts = [".step", ".stp", ".iges", ".igs", ".glb", ".gltf", ".obj", ".stl"]
    files = []
    for p in root.rglob("*"):
        if p.is_file():
            files.append(p)
    for ext in exts:
        cand = [p for p in files if p.suffix.lower() == ext]
        if cand:
            return sorted(cand, key=lambda x: x.name.lower())[0]
    return None

def now_iso() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

def freecad_step_to_obj(cfg: Config, input_path: Path, output_obj: Path) -> str:
    """
    Convert STEP/IGES to OBJ using FreeCADCmd/freecadcmd.

    FreeCAD CLI behavior differs across builds/OS:
    - Some builds accept: FreeCADCmd -c script.py -- <args>
    - Others accept:       FreeCADCmd script.py -- <args>

    We try both (failover) to be robust.
    Returns captured output (truncated).
    """
    freecad = cfg.freecad_cmd or which_any(("FreeCADCmd", "freecadcmd", "FreeCAD"))
    if not freecad:
        raise RuntimeError("FreeCADCmd/freecadcmd not found in PATH; set FREECAD_CMD")

    script = Path("/app/scripts/freecad_export_obj.py")
    if not script.exists():
        raise RuntimeError("freecad_export_obj.py not found in image at /app/scripts")

    base_args = ["--",
                 "--input", str(input_path),
                 "--output", str(output_obj),
                 "--linear-deflection", str(cfg.freecad_linear_deflection),
                 "--angular-deflection-deg", str(cfg.freecad_angular_deflection_deg)]

    prefixes = (["xvfb-run", "-a"] if cfg.use_xvfb else [])

    candidates = [
        prefixes + [freecad, "-c", str(script)] + base_args,
        prefixes + [freecad, str(script)] + base_args,
    ]

    last_out = ""
    last_code = -1
    for cmd in candidates:
        code, out = run_cmd(cmd, timeout_sec=3600)
        last_out, last_code = out, code
        if code == 0:
            return out

    raise RuntimeError(f"FreeCAD conversion failed (code={last_code}). Output:\n{last_out}")

def blender_to_glb(cfg: Config, input_path: Path, output_glb: Path, meta_json_path: Path) -> str:
    blender = cfg.blender_cmd or which_any(("blender",))
    if not blender:
        raise RuntimeError("blender not found in PATH; set BLENDER_CMD")

    script = Path("/app/scripts/blender_convert_obj_to_glb.py")
    if not script.exists():
        raise RuntimeError("blender_convert_obj_to_glb.py not found in image at /app/scripts")

    cmd = [
        blender,
        "--background",
        "--factory-startup",
        "--python",
        str(script),
        "--",
        "--input", str(input_path),
        "--output", str(output_glb),
        "--meta-json", str(meta_json_path),
        "--max-triangles", str(cfg.max_triangles),
    ]
    code, out = run_cmd(cmd, timeout_sec=3600)
    if code != 0:
        raise RuntimeError(f"Blender convert failed (code={code}). Output:\n{out}")
    return out

def blender_render_preview(cfg: Config, input_glb: Path, preview_png: Path) -> str:
    blender = cfg.blender_cmd or which_any(("blender",))
    if not blender:
        raise RuntimeError("blender not found in PATH; set BLENDER_CMD")

    script = Path("/app/scripts/blender_render_preview.py")
    if not script.exists():
        raise RuntimeError("blender_render_preview.py not found in image at /app/scripts")

    cmd = [
        blender,
        "--background",
        "--factory-startup",
        "--python",
        str(script),
        "--",
        "--input", str(input_glb),
        "--output", str(preview_png),
        "--size", str(cfg.preview_size),
        "--samples", str(cfg.preview_samples),
    ]
    code, out = run_cmd(cmd, timeout_sec=3600)
    if code != 0:
        raise RuntimeError(f"Blender preview failed (code={code}). Output:\n{out}")
    return out

def gltf_optimize(cfg: Config, input_glb: Path, output_glb: Path) -> str:
    cmd = [cfg.gltf_transform_cmd, "optimize", str(input_glb), str(output_glb)]
    code, out = run_cmd(cmd, timeout_sec=3600)
    if code != 0:
        raise RuntimeError(f"gltf-transform optimize failed (code={code}). Output:\n{out}")
    return out

def gltf_compress_draco(cfg: Config, input_glb: Path, output_glb: Path) -> str:
    cmd = [cfg.gltf_transform_cmd, "draco", str(input_glb), str(output_glb), "--method", cfg.draco_method]
    code, out = run_cmd(cmd, timeout_sec=3600)
    if code != 0:
        raise RuntimeError(f"gltf-transform draco failed (code={code}). Output:\n{out}")
    return out

def gltf_compress_meshopt(cfg: Config, input_glb: Path, output_glb: Path) -> str:
    cmd = [cfg.gltf_transform_cmd, "meshopt", str(input_glb), str(output_glb), "--level", cfg.meshopt_level]
    code, out = run_cmd(cmd, timeout_sec=3600)
    if code != 0:
        raise RuntimeError(f"gltf-transform meshopt failed (code={code}). Output:\n{out}")
    return out

def process_job(cfg: Config, dx: DirectusClient, job_id: str) -> None:
    t0 = time.perf_counter()
    log("info", "Start job", job_id=job_id)

    job = dx.get_item("vp_3d_jobs", job_id)
    if not job:
        raise RuntimeError("job_not_found")

    input_file_id = job.get("input_file")
    if not input_file_id:
        raise RuntimeError("job_missing_input_file")

    meta = job.get("meta") or {}
    attempts = int(meta.get("attempts") or 0) + 1
    meta["attempts"] = attempts
    meta.setdefault("started_at", now_iso())
    dx.update_item("vp_3d_jobs", job_id, {"status": "processing", "progress": 1, "meta": meta})

    fmeta = dx.get_file(input_file_id)
    filename_download = (fmeta.get("filename_download") or fmeta.get("title") or str(input_file_id))
    filesize = int(fmeta.get("filesize") or 0)
    if filesize and filesize > cfg.max_input_bytes:
        raise RuntimeError(f"input_too_large_bytes:{filesize}")

    tmp_root = Path(cfg.tmp_root)
    tmp_root.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(prefix=f"job_{job_id}_", dir=str(tmp_root)) as td:
        wd = Path(td)

        dx.update_item("vp_3d_jobs", job_id, {"progress": 5})
        input_path = wd / safe_filename(filename_download)
        dx.download_asset(input_file_id, input_path)
        if input_path.stat().st_size > cfg.max_input_bytes:
            raise RuntimeError(f"input_too_large_bytes:{input_path.stat().st_size}")

        dx.update_item("vp_3d_jobs", job_id, {"progress": 10})

        chosen_input = input_path
        if input_path.suffix.lower() == ".zip":
            unzip_dir = wd / "zip"
            unzip_dir.mkdir(parents=True, exist_ok=True)
            unzip_safely(input_path, unzip_dir)
            picked = pick_input_from_dir(unzip_dir)
            if not picked:
                raise RuntimeError("zip_no_supported_files")
            chosen_input = picked
            meta["zip"] = {"picked": str(picked.relative_to(unzip_dir)).replace("\\", "/")}
            dx.update_item("vp_3d_jobs", job_id, {"meta": meta})

        ext = chosen_input.suffix.lower()
        meta["detected_ext"] = ext
        dx.update_item("vp_3d_jobs", job_id, {"meta": meta})

        raw_glb = wd / "model.raw.glb"
        meta_json = wd / "model.meta.json"
        preview_png = wd / "preview.png"

        timings: Dict[str, float] = {}
        tool_logs: Dict[str, str] = {}
        geometry_meta: Dict[str, Any] = {}

        t1 = time.perf_counter()
        if ext in (".glb",):
            shutil.copyfile(chosen_input, raw_glb)
            tool_logs["convert"] = "pass-through glb"
        elif ext in (".gltf", ".obj", ".stl"):
            tool_logs["blender_convert"] = blender_to_glb(cfg, chosen_input, raw_glb, meta_json)
        elif ext in (".step", ".stp", ".iges", ".igs"):
            obj_path = wd / "model.obj"
            tool_logs["freecad"] = freecad_step_to_obj(cfg, chosen_input, obj_path)
            tool_logs["blender_convert"] = blender_to_glb(cfg, obj_path, raw_glb, meta_json)
        else:
            raise RuntimeError(f"unsupported_input_ext:{ext}")
        timings["convert_sec"] = time.perf_counter() - t1

        if meta_json.exists():
            try:
                geometry_meta = json.loads(meta_json.read_text("utf-8"))
            except Exception:
                geometry_meta = {"meta_json_parse_error": True}

        tri_count = int((geometry_meta.get("triangles") or 0))
        if tri_count and tri_count > cfg.max_triangles:
            raise RuntimeError(f"triangles_over_limit:{tri_count}")

        dx.update_item("vp_3d_jobs", job_id, {"progress": 45})

        t2 = time.perf_counter()
        tool_logs["preview"] = blender_render_preview(cfg, raw_glb, preview_png)
        timings["preview_sec"] = time.perf_counter() - t2

        preview_file_id = dx.upload_file(
            preview_png,
            title=f"vp3d_preview_job_{job_id}.png",
            filename_download=f"preview_job_{job_id}.png",
        )
        dx.update_item("vp_3d_jobs", job_id, {
            "preview_file": preview_file_id,
            "progress": 60,
            "meta": {
                **meta,
                "geometry": geometry_meta,
                "files": {"raw_glb_bytes": raw_glb.stat().st_size, "preview_bytes": preview_png.stat().st_size},
                "timings": timings,
            },
        })

        final_glb = wd / "model.final.glb"
        t3 = time.perf_counter()
        if cfg.do_optimize:
            tool_logs["gltf_optimize"] = gltf_optimize(cfg, raw_glb, final_glb)
        else:
            shutil.copyfile(raw_glb, final_glb)
            tool_logs["gltf_optimize"] = "skipped"
        timings["optimize_sec"] = time.perf_counter() - t3

        compressed_glb = wd / "model.compressed.glb"
        t4 = time.perf_counter()
        if cfg.compress_mode == "draco":
            tool_logs["gltf_draco"] = gltf_compress_draco(cfg, final_glb, compressed_glb)
            final_path = compressed_glb
        elif cfg.compress_mode == "meshopt":
            tool_logs["gltf_meshopt"] = gltf_compress_meshopt(cfg, final_glb, compressed_glb)
            final_path = compressed_glb
        elif cfg.compress_mode in ("none", "", "off"):
            final_path = final_glb
        else:
            raise RuntimeError(f"bad_compress_mode:{cfg.compress_mode}")
        timings["compress_sec"] = time.perf_counter() - t4

        out_bytes = final_path.stat().st_size
        if out_bytes > cfg.max_output_bytes:
            raise RuntimeError(f"output_too_large_bytes:{out_bytes}")

        dx.update_item("vp_3d_jobs", job_id, {"progress": 85})

        output_file_id = dx.upload_file(
            final_path,
            title=f"vp3d_model_job_{job_id}.glb",
            filename_download=f"model_job_{job_id}.glb",
        )

        total_sec = time.perf_counter() - t0
        timings["total_sec"] = total_sec

        meta_out = {
            **meta,
            "geometry": geometry_meta,
            "files": {
                "input_bytes": chosen_input.stat().st_size,
                "output_bytes": out_bytes,
                "preview_bytes": preview_png.stat().st_size,
            },
            "timings": timings,
            "tool_logs": {k: (v[:4000] + "...[truncated]") if len(v) > 4000 else v for k, v in tool_logs.items()},
        }

        dx.update_item("vp_3d_jobs", job_id, {
            "status": "completed",
            "progress": 100,
            "output_file": output_file_id,
            "meta": meta_out,
            "completed_at": now_iso(),
        })

        log("info", "Job completed", job_id=job_id, output_file=output_file_id, preview_file=preview_file_id, triangles=tri_count, seconds=round(total_sec, 3))

def handle_job_error(cfg: Config, dx: DirectusClient, job_id: str, err: Exception) -> None:
    msg = str(err)
    tb = traceback.format_exc()
    log("error", "Job failed", job_id=job_id, error=msg)

    try:
        job = dx.get_item("vp_3d_jobs", job_id) or {}
        meta = job.get("meta") or {}
        attempts = int(meta.get("attempts") or 1)
        meta["last_error"] = msg
        meta["last_error_at"] = now_iso()
        meta["last_trace"] = (tb[:6000] + "\n...[truncated]...") if len(tb) > 6000 else tb

        if attempts < cfg.max_attempts:
            dx.update_item("vp_3d_jobs", job_id, {
                "status": "pending",
                "progress": 0,
                "error": msg[:2000],
                "meta": meta,
            })
        else:
            dx.update_item("vp_3d_jobs", job_id, {
                "status": "failed",
                "progress": 100,
                "error": msg[:4000],
                "meta": meta,
                "completed_at": now_iso(),
            })
    except Exception as e2:
        log("error", "Failed to update job after error", job_id=job_id, error=str(e2))

def main() -> None:
    cfg = Config.from_env()

    freecad = cfg.freecad_cmd or which_any(("FreeCADCmd", "freecadcmd"))
    blender = cfg.blender_cmd or which_any(("blender",))
    if not blender:
        raise SystemExit("blender not found in PATH (install it or set BLENDER_CMD)")
    if not freecad:
        raise SystemExit("FreeCADCmd/freecadcmd not found in PATH (install it or set FREECAD_CMD)")

    log("info", "Worker boot", directus_url=cfg.directus_url, redis_url=cfg.redis_url, queue=cfg.redis_queue, compress=cfg.compress_mode)

    dx = DirectusClient(base_url=cfg.directus_url, token=cfg.directus_token, timeout_sec=cfg.directus_timeout_sec)
    qcfg = QueueConfig(redis_url=cfg.redis_url, queue_key=cfg.redis_queue, processing_key=cfg.redis_processing)
    q = RedisQueue(qcfg)

    while True:
        job_id = q.reserve(timeout=cfg.poll_timeout_sec)
        if not job_id:
            continue

        try:
            process_job(cfg, dx, job_id)
            q.ack(job_id)
        except Exception as e:
            handle_job_error(cfg, dx, job_id, e)
            try:
                job = dx.get_item("vp_3d_jobs", job_id) or {}
                meta = job.get("meta") or {}
                attempts = int(meta.get("attempts") or 1)
                if attempts < cfg.max_attempts:
                    log("warning", "Requeue job", job_id=job_id, attempts=attempts)
                    q.ack(job_id)
                    q.enqueue(job_id)
                else:
                    q.ack(job_id)
            except Exception as e2:
                log("error", "Queue ack/requeue failed", job_id=job_id, error=str(e2))
                try:
                    q.ack(job_id)
                except Exception:
                    pass

if __name__ == "__main__":
    main()
```

## Directus schema для `vp_3d_jobs` и права сервисного токена

Почему STEP>GLB делается через FreeCAD+Blender, а не “FreeCAD сразу в glTF”: у FreeCAD есть известное ограничение, что glTF exporter завязан на GUI-модуль (и это ломает чисто headless-подход). Для MVP это оправдывает схему “STEP/IGES > OBJ (FreeCAD) > GLB (Blender)”. ?cite?turn19view2?  

### JSON-описание коллекции `vp_3d_jobs`

Это минимальный schema-snapshot в формате Directus schema export: **collections + fields + relations**. Его можно применить через ваш текущий инструмент миграции схемы (или вставить вручную через Data Studio, ориентируясь на типы/поля).

```json
{
  "data": {
    "collections": [
      {
        "collection": "vp_3d_jobs",
        "meta": {
          "accountability": "all",
          "archive_app_filter": true,
          "archive_field": null,
          "archive_value": null,
          "collapse": "open",
          "collection": "vp_3d_jobs",
          "color": null,
          "display_template": "Job {{id}} — {{status}}",
          "group": null,
          "hidden": false,
          "icon": "precision_manufacturing",
          "item_duplication_fields": null,
          "note": "Очередь конвертации 3D (input -> GLB + preview) для MVP.",
          "preview_url": null,
          "singleton": false,
          "sort": 31,
          "sort_field": null,
          "translations": null,
          "unarchive_value": null,
          "versioning": false
        },
        "schema": {
          "name": "vp_3d_jobs"
        }
      }
    ],
    "fields": [
      {
        "collection": "vp_3d_jobs",
        "field": "id",
        "type": "integer",
        "meta": {
          "collection": "vp_3d_jobs",
          "conditions": null,
          "display": null,
          "display_options": null,
          "field": "id",
          "group": null,
          "hidden": true,
          "interface": "input",
          "note": null,
          "options": null,
          "readonly": true,
          "required": false,
          "searchable": true,
          "sort": 1,
          "special": null,
          "translations": null,
          "validation": null,
          "validation_message": null,
          "width": "full"
        },
        "schema": {
          "name": "id",
          "table": "vp_3d_jobs",
          "data_type": "integer",
          "default_value": "nextval('vp_3d_jobs_id_seq'::regclass)",
          "max_length": null,
          "numeric_precision": 32,
          "numeric_scale": 0,
          "is_nullable": false,
          "is_unique": true,
          "is_indexed": false,
          "is_primary_key": true,
          "is_generated": false,
          "generation_expression": null,
          "has_auto_increment": true,
          "foreign_key_table": null,
          "foreign_key_column": null
        }
      },
      {
        "collection": "vp_3d_jobs",
        "field": "status",
        "type": "string",
        "meta": {
          "collection": "vp_3d_jobs",
          "conditions": null,
          "display": null,
          "display_options": null,
          "field": "status",
          "group": null,
          "hidden": false,
          "interface": "select-dropdown",
          "note": "pending | processing | completed | failed",
          "options": {
            "choices": [
              { "text": "pending", "value": "pending" },
              { "text": "processing", "value": "processing" },
              { "text": "completed", "value": "completed" },
              { "text": "failed", "value": "failed" }
            ]
          },
          "readonly": false,
          "required": true,
          "searchable": true,
          "sort": 2,
          "special": null,
          "translations": null,
          "validation": null,
          "validation_message": null,
          "width": "full"
        },
        "schema": {
          "name": "status",
          "table": "vp_3d_jobs",
          "data_type": "character varying",
          "default_value": "''pending''",
          "max_length": 16,
          "numeric_precision": null,
          "numeric_scale": null,
          "is_nullable": false,
          "is_unique": false,
          "is_indexed": false,
          "is_primary_key": false,
          "is_generated": false,
          "generation_expression": null,
          "has_auto_increment": false,
          "foreign_key_table": null,
          "foreign_key_column": null
        }
      },
      {
        "collection": "vp_3d_jobs",
        "field": "input_file",
        "type": "uuid",
        "meta": {
          "collection": "vp_3d_jobs",
          "conditions": null,
          "display": null,
          "display_options": null,
          "field": "input_file",
          "group": null,
          "hidden": false,
          "interface": "file",
          "note": "Входной файл (STEP/IGES/STL/OBJ/GLB/GLTF/ZIP)",
          "options": null,
          "readonly": false,
          "required": true,
          "searchable": true,
          "sort": 3,
          "special": ["file"],
          "translations": null,
          "validation": null,
          "validation_message": null,
          "width": "full"
        },
        "schema": {
          "name": "input_file",
          "table": "vp_3d_jobs",
          "data_type": "uuid",
          "default_value": null,
          "max_length": null,
          "numeric_precision": null,
          "numeric_scale": null,
          "is_nullable": false,
          "is_unique": false,
          "is_indexed": false,
          "is_primary_key": false,
          "is_generated": false,
          "generation_expression": null,
          "has_auto_increment": false,
          "foreign_key_table": "directus_files",
          "foreign_key_column": "id"
        }
      },
      {
        "collection": "vp_3d_jobs",
        "field": "output_file",
        "type": "uuid",
        "meta": {
          "collection": "vp_3d_jobs",
          "conditions": null,
          "display": null,
          "display_options": null,
          "field": "output_file",
          "group": null,
          "hidden": false,
          "interface": "file",
          "note": "Выходной GLB",
          "options": null,
          "readonly": false,
          "required": false,
          "searchable": true,
          "sort": 4,
          "special": ["file"],
          "translations": null,
          "validation": null,
          "validation_message": null,
          "width": "full"
        },
        "schema": {
          "name": "output_file",
          "table": "vp_3d_jobs",
          "data_type": "uuid",
          "default_value": null,
          "max_length": null,
          "numeric_precision": null,
          "numeric_scale": null,
          "is_nullable": true,
          "is_unique": false,
          "is_indexed": false,
          "is_primary_key": false,
          "is_generated": false,
          "generation_expression": null,
          "has_auto_increment": false,
          "foreign_key_table": "directus_files",
          "foreign_key_column": "id"
        }
      },
      {
        "collection": "vp_3d_jobs",
        "field": "preview_file",
        "type": "uuid",
        "meta": {
          "collection": "vp_3d_jobs",
          "conditions": null,
          "display": null,
          "display_options": null,
          "field": "preview_file",
          "group": null,
          "hidden": false,
          "interface": "file-image",
          "note": "PNG превью",
          "options": null,
          "readonly": false,
          "required": false,
          "searchable": true,
          "sort": 5,
          "special": ["file"],
          "translations": null,
          "validation": null,
          "validation_message": null,
          "width": "full"
        },
        "schema": {
          "name": "preview_file",
          "table": "vp_3d_jobs",
          "data_type": "uuid",
          "default_value": null,
          "max_length": null,
          "numeric_precision": null,
          "numeric_scale": null,
          "is_nullable": true,
          "is_unique": false,
          "is_indexed": false,
          "is_primary_key": false,
          "is_generated": false,
          "generation_expression": null,
          "has_auto_increment": false,
          "foreign_key_table": "directus_files",
          "foreign_key_column": "id"
        }
      },
      {
        "collection": "vp_3d_jobs",
        "field": "progress",
        "type": "integer",
        "meta": {
          "collection": "vp_3d_jobs",
          "conditions": null,
          "display": null,
          "display_options": null,
          "field": "progress",
          "group": null,
          "hidden": false,
          "interface": "input",
          "note": "0..100",
          "options": null,
          "readonly": false,
          "required": true,
          "searchable": true,
          "sort": 6,
          "special": null,
          "translations": null,
          "validation": null,
          "validation_message": null,
          "width": "full"
        },
        "schema": {
          "name": "progress",
          "table": "vp_3d_jobs",
          "data_type": "integer",
          "default_value": 0,
          "max_length": null,
          "numeric_precision": 32,
          "numeric_scale": 0,
          "is_nullable": false,
          "is_unique": false,
          "is_indexed": false,
          "is_primary_key": false,
          "is_generated": false,
          "generation_expression": null,
          "has_auto_increment": false,
          "foreign_key_table": null,
          "foreign_key_column": null
        }
      },
      {
        "collection": "vp_3d_jobs",
        "field": "error",
        "type": "text",
        "meta": {
          "collection": "vp_3d_jobs",
          "conditions": null,
          "display": null,
          "display_options": null,
          "field": "error",
          "group": null,
          "hidden": false,
          "interface": "textarea",
          "note": "Ошибка конвертации (если есть).",
          "options": null,
          "readonly": false,
          "required": false,
          "searchable": true,
          "sort": 7,
          "special": null,
          "translations": null,
          "validation": null,
          "validation_message": null,
          "width": "full"
        },
        "schema": {
          "name": "error",
          "table": "vp_3d_jobs",
          "data_type": "text",
          "default_value": null,
          "max_length": null,
          "numeric_precision": null,
          "numeric_scale": null,
          "is_nullable": true,
          "is_unique": false,
          "is_indexed": false,
          "is_primary_key": false,
          "is_generated": false,
          "generation_expression": null,
          "has_auto_increment": false,
          "foreign_key_table": null,
          "foreign_key_column": null
        }
      },
      {
        "collection": "vp_3d_jobs",
        "field": "meta",
        "type": "json",
        "meta": {
          "collection": "vp_3d_jobs",
          "conditions": null,
          "display": null,
          "display_options": null,
          "field": "meta",
          "group": null,
          "hidden": false,
          "interface": "input-code",
          "note": "JSON: bbox, triangles, timings, sizes, tool logs.",
          "options": null,
          "readonly": false,
          "required": false,
          "searchable": true,
          "sort": 8,
          "special": null,
          "translations": null,
          "validation": null,
          "validation_message": null,
          "width": "full"
        },
        "schema": {
          "name": "meta",
          "table": "vp_3d_jobs",
          "data_type": "json",
          "default_value": null,
          "max_length": null,
          "numeric_precision": null,
          "numeric_scale": null,
          "is_nullable": true,
          "is_unique": false,
          "is_indexed": false,
          "is_primary_key": false,
          "is_generated": false,
          "generation_expression": null,
          "has_auto_increment": false,
          "foreign_key_table": null,
          "foreign_key_column": null
        }
      }
    ],
    "relations": [
      {
        "collection": "vp_3d_jobs",
        "field": "input_file",
        "related_collection": "directus_files",
        "meta": {
          "junction_field": null,
          "many_collection": "vp_3d_jobs",
          "many_field": "input_file",
          "one_allowed_collections": null,
          "one_collection": "directus_files",
          "one_collection_field": null,
          "one_deselect_action": "nullify",
          "one_field": null,
          "sort_field": null
        },
        "schema": {
          "table": "vp_3d_jobs",
          "column": "input_file",
          "foreign_key_table": "directus_files",
          "foreign_key_column": "id",
          "constraint_name": "vp_3d_jobs_input_file_foreign",
          "on_update": "NO ACTION",
          "on_delete": "NO ACTION"
        }
      }
    ]
  }
}
```

### Права сервисного токена (policy)

Конвертеру и WP endpoints нужен **service token**, который может:
- CRUD по `vp_3d_jobs`
- загрузка файлов через POST `/files` (create на `directus_files`)
- чтение файловых метаданных через GET `/files/{id}` (read на `directus_files`)
- скачивание входных ассетов через `/assets/{id}` (обычно достаточно read на `directus_files`). ?cite?turn3view0?turn3view2?turn3view1?  

Минимальный набор permissions (пример в стиле Directus permissions export). Если вы хотите переиспользовать существующую политику **“VP Service Policy”**, просто добавьте эти записи к ней:

```json
[
  { "collection": "vp_3d_jobs", "action": "create", "permissions": {}, "validation": {}, "presets": {}, "fields": ["*"], "policy": "__VP_SERVICE_POLICY_ID__" },
  { "collection": "vp_3d_jobs", "action": "read",   "permissions": {}, "validation": {}, "presets": {}, "fields": ["*"], "policy": "__VP_SERVICE_POLICY_ID__" },
  { "collection": "vp_3d_jobs", "action": "update", "permissions": {}, "validation": {}, "presets": {}, "fields": ["*"], "policy": "__VP_SERVICE_POLICY_ID__" },
  { "collection": "vp_3d_jobs", "action": "delete", "permissions": {}, "validation": {}, "presets": {}, "fields": ["*"], "policy": "__VP_SERVICE_POLICY_ID__" },

  { "collection": "directus_files", "action": "create", "permissions": {}, "validation": {}, "presets": {}, "fields": ["*"], "policy": "__VP_SERVICE_POLICY_ID__" },
  { "collection": "directus_files", "action": "read",   "permissions": {}, "validation": {}, "presets": {}, "fields": ["*"], "policy": "__VP_SERVICE_POLICY_ID__" },
  { "collection": "directus_files", "action": "update", "permissions": {}, "validation": {}, "presets": {}, "fields": ["*"], "policy": "__VP_SERVICE_POLICY_ID__" }
]
```

Создание static token для сервисного пользователя делается в Directus Data Studio на странице пользователя (это прямой рекомендуемый путь для “admin static token” в примерах Directus API). ?cite?turn3view3?  

## WordPress endpoints для jobs (MU-plugin)

WordPress REST endpoints регистрируются через `register_rest_route()` в `rest_api_init`. ?cite?turn4search3?turn4search11?  

Логика:
- Проверяем cookie `vp_dx_at` и **валидируем** токен, дергая `GET Directus /users/me` (без логирования токена).
- Создаём job в Directus через service token.
- Пушим job_id в Redis через минимальный RESP-клиент (без PHP Redis extension).
- `GET job` возвращает статус + asset URLs (`/assets/<file_id>`) для viewer.

### Файл MU-plugin

Путь:  
`/opt/vseponyatno/docker/volumes/wordpress/wp-content/mu-plugins/vp-3d-jobs.php`

```php
<?php
/**
 * MU Plugin: VP 3D Jobs API (MVP)
 *
 * Endpoints:
 *   POST /wp-json/vp/v1/3d/jobs         { input_file_id: "<directus_files.id>" } -> { job_id }
 *   GET  /wp-json/vp/v1/3d/jobs/{id}    -> job status/progress/output/preview/meta (+ asset URLs)
 *
 * Security:
 * - Requires vp_dx_at cookie (Directus access token) and validates it against Directus /users/me.
 * - All Directus writes happen with a Directus SERVICE token (server-to-server).
 *
 * Notes:
 * - Does NOT depend on profiles/tenants. It must not block 3D core.
 * - Avoids logging tokens/cookies.
 */

if (!defined('ABSPATH')) { exit; }

add_action('rest_api_init', function () {
    register_rest_route('vp/v1', '/3d/jobs', [
        [
            'methods'  => 'POST',
            'callback' => 'vp3d_create_job',
            'permission_callback' => 'vp3d_permission_check',
        ],
    ]);

    register_rest_route('vp/v1', '/3d/jobs/(?P<id>\d+)', [
        [
            'methods'  => 'GET',
            'callback' => 'vp3d_get_job',
            'permission_callback' => 'vp3d_permission_check',
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) > 0;
                    },
                ],
            ],
        ],
    ]);
});

function vp3d_env($key, $default = '') {
    $val = getenv($key);
    if ($val === false || $val === null || $val === '') return $default;
    return $val;
}

function vp3d_directus_internal_url() {
    return rtrim(vp3d_env('VP_DIRECTUS_URL_INTERNAL', 'http://directus:8055'), '/');
}
function vp3d_directus_public_url() {
    $v = vp3d_env('VP_DIRECTUS_PUBLIC_URL', '');
    if ($v) return rtrim($v, '/');
    return vp3d_directus_internal_url();
}
function vp3d_directus_service_token() {
    return vp3d_env('VP_DIRECTUS_SERVICE_TOKEN', '');
}

function vp3d_get_current_directus_user() {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (empty($_COOKIE['vp_dx_at'])) {
        $cached = new WP_Error('vp3d_no_token', 'Not authenticated', ['status' => 401]);
        return $cached;
    }

    $token = $_COOKIE['vp_dx_at'];
    $url = vp3d_directus_internal_url() . '/users/me';

    $res = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
        ],
    ]);

    if (is_wp_error($res)) {
        $cached = new WP_Error('vp3d_directus_unreachable', 'Directus unreachable', ['status' => 502]);
        return $cached;
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300) {
        $cached = new WP_Error('vp3d_bad_token', 'Invalid session', ['status' => 401]);
        return $cached;
    }

    $user = isset($json['data']) ? $json['data'] : null;
    if (!$user || empty($user['id'])) {
        $cached = new WP_Error('vp3d_user_missing', 'Invalid session', ['status' => 401]);
        return $cached;
    }

    $cached = $user;
    return $cached;
}

function vp3d_permission_check($request) {
    $user = vp3d_get_current_directus_user();
    if (is_wp_error($user)) return $user;
    return true;
}

function vp3d_redis_lpush($key, $value) {
    $host = vp3d_env('VP_REDIS_HOST', 'redis');
    $port = intval(vp3d_env('VP_REDIS_PORT', '6379'));
    $timeout = floatval(vp3d_env('VP_REDIS_TIMEOUT_SEC', '1.0'));

    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        return new WP_Error('vp3d_redis_connect_failed', 'Redis connect failed', ['status' => 502]);
    }

    $cmd = vp3d_resp_command(['LPUSH', $key, strval($value)]);
    fwrite($fp, $cmd);

    $reply = vp3d_resp_read($fp);
    fclose($fp);

    if (is_wp_error($reply)) return $reply;
    return $reply;
}

function vp3d_resp_command($parts) {
    $out = '*' . count($parts) . "\r\n";
    foreach ($parts as $p) {
        $p = (string)$p;
        $out .= '$' . strlen($p) . "\r\n" . $p . "\r\n";
    }
    return $out;
}

function vp3d_resp_read($fp) {
    $line = fgets($fp);
    if ($line === false) {
        return new WP_Error('vp3d_redis_no_reply', 'Redis no reply', ['status' => 502]);
    }
    $type = $line[0];
    $payload = substr($line, 1, -2); // strip type + CRLF

    if ($type === '+') return $payload;
    if ($type === ':') return intval($payload);
    if ($type === '-') return new WP_Error('vp3d_redis_error', 'Redis error: ' . $payload, ['status' => 502]);

    if ($type === '$') {
        $len = intval($payload);
        if ($len === -1) return null;
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($fp, $len - strlen($data));
            if ($chunk === false || $chunk === '') break;
            $data .= $chunk;
        }
        fread($fp, 2); // trailing CRLF
        return $data;
    }

    return new WP_Error('vp3d_redis_bad_reply', 'Redis bad reply', ['status' => 502]);
}

function vp3d_directus_request($method, $path, $body = null) {
    $token = vp3d_directus_service_token();
    if (!$token) {
        return new WP_Error('vp3d_service_token_missing', 'Service token not configured', ['status' => 500]);
    }

    $url = vp3d_directus_internal_url() . $path;

    $args = [
        'method'  => $method,
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
    ];

    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }

    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) return $res;

    $code = wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);
    $json = json_decode($raw, true);

    if ($code < 200 || $code >= 300) {
        return new WP_Error(
            'vp3d_directus_error',
            'Directus error',
            [
                'status' => 502,
                'directus_status' => $code,
                'directus_body' => is_array($json) ? $json : substr($raw, 0, 1000),
            ]
        );
    }

    return $json;
}

function vp3d_is_uuid($s) {
    return is_string($s) && preg_match('/^[0-9a-fA-F-]{32,36}$/', $s);
}

function vp3d_create_job(WP_REST_Request $request) {
    $user = vp3d_get_current_directus_user();
    if (is_wp_error($user)) return $user;

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_Error('vp3d_bad_json', 'Invalid JSON body', ['status' => 400]);
    }

    $input_file_id = isset($payload['input_file_id']) ? $payload['input_file_id'] : '';
    if (!vp3d_is_uuid($input_file_id)) {
        return new WP_Error('vp3d_bad_input_file_id', 'input_file_id must be a Directus file UUID', ['status' => 400]);
    }

    $job_body = [
        'status' => 'pending',
        'input_file' => $input_file_id,
        'progress' => 0,
        'meta' => [
            'requested_by' => $user['id'],
            'requested_at' => gmdate('c'),
            'source' => 'wp',
        ],
    ];

    $dx = vp3d_directus_request('POST', '/items/vp_3d_jobs', $job_body);
    if (is_wp_error($dx)) return $dx;

    $job = isset($dx['data']) ? $dx['data'] : null;
    if (!$job || empty($job['id'])) {
        return new WP_Error('vp3d_job_create_failed', 'Job create failed', ['status' => 502]);
    }

    $job_id = intval($job['id']);

    $queue = vp3d_env('VP_REDIS_QUEUE', 'vp:3d:jobs');
    $r = vp3d_redis_lpush($queue, strval($job_id));
    if (is_wp_error($r)) {
        vp3d_directus_request('PATCH', '/items/vp_3d_jobs/' . $job_id, [
            'status' => 'failed',
            'progress' => 100,
            'error' => 'enqueue_failed',
        ]);
        return $r;
    }

    return [
        'job_id' => $job_id,
    ];
}

function vp3d_get_job(WP_REST_Request $request) {
    $user = vp3d_get_current_directus_user();
    if (is_wp_error($user)) return $user;

    $job_id = intval($request['id']);

    $dx = vp3d_directus_request('GET', '/items/vp_3d_jobs/' . $job_id, null);
    if (is_wp_error($dx)) return $dx;

    $job = isset($dx['data']) ? $dx['data'] : null;
    if (!$job) {
        return new WP_Error('vp3d_not_found', 'Job not found', ['status' => 404]);
    }

    if (isset($job['meta']['requested_by']) && $job['meta']['requested_by'] !== $user['id']) {
        return new WP_Error('vp3d_forbidden', 'Forbidden', ['status' => 403]);
    }

    $public = vp3d_directus_public_url();

    $output_id = isset($job['output_file']) ? $job['output_file'] : null;
    $preview_id = isset($job['preview_file']) ? $job['preview_file'] : null;

    $asset = [
        'output_glb'  => $output_id ? ($public . '/assets/' . $output_id) : null,
        'preview_png' => $preview_id ? ($public . '/assets/' . $preview_id) : null,
    ];

    return [
        'id' => intval($job['id']),
        'status' => isset($job['status']) ? $job['status'] : null,
        'progress' => isset($job['progress']) ? intval($job['progress']) : null,
        'input_file' => isset($job['input_file']) ? $job['input_file'] : null,
        'output_file' => $output_id,
        'preview_file' => $preview_id,
        'error' => isset($job['error']) ? $job['error'] : null,
        'meta' => isset($job['meta']) ? $job['meta'] : null,
        'created_at' => isset($job['created_at']) ? $job['created_at'] : null,
        'updated_at' => isset($job['updated_at']) ? $job['updated_at'] : null,
        'completed_at' => isset($job['completed_at']) ? $job['completed_at'] : null,
        'assets' => $asset,
    ];
}
```

## Минимальный 3D viewer для mobile

### Почему viewer обязан уметь Draco и Meshopt

Если вы включаете mesh compression на стороне конвертера:
- Draco требует подключения DRACOLoader и `loader.setDRACOLoader(...)` для декодирования расширения `KHR_draco_mesh_compression`. ?cite?turn21view0?turn21view2?  
- Meshopt требует `loader.setMeshoptDecoder(...)` для `EXT_meshopt_compression`. ?cite?turn21view0?  

Meshopt также декодируется быстро, но сам по себе не повышает FPS — это про размер передачи/распаковку; для FPS нужны ограничения polycount/drawcalls. ?cite?turn22view0?  

### UI-компоненты MVP

Компоненты (минимум, но под mobile-first):
- **LoadingOverlay**: status + progress + preview image + retry/open.
- **HUD**: reset camera + текстовая статистика (triangles, load time).
- **Fallback**: если WebGL недоступен.
- **ViewerCore**: Three.js scene + loader + controls + resize debounce.

### Файлы viewer

#### `viewer/index.html`

```html
<!doctype html>
<html lang="ru">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <title>ВСЁПОНЯТНО — 3D Viewer (MVP)</title>
    <link rel="stylesheet" href="./styles.css" />
  </head>
  <body>
    <div id="app">
      <canvas id="vp-canvas"></canvas>

      <div id="overlay" class="overlay">
        <div class="card">
          <div class="title">Загрузка 3D</div>
          <div class="status" id="statusText">Подключение…</div>
          <div class="progress">
            <div class="bar" id="progressBar"></div>
          </div>
          <div class="preview">
            <img id="previewImg" alt="preview" />
          </div>
          <div class="actions">
            <button id="retryBtn" class="btn" style="display:none;">Повторить</button>
            <button id="openBtn" class="btn" style="display:none;">Открыть модель</button>
          </div>
          <div class="hint" id="hintText"></div>
        </div>
      </div>

      <div id="hud" class="hud" style="display:none;">
        <button id="resetBtn" class="icon-btn" title="Reset camera">?</button>
        <div id="stats" class="stats"></div>
      </div>

      <div id="fallback" class="fallback" style="display:none;">
        <div class="card">
          <div class="title">WebGL недоступен</div>
          <div class="status">Проверь браузер/устройство или открой в другом браузере.</div>
        </div>
      </div>
    </div>

    <!-- MVP importmap: for production, vendor these locally -->
    <script type="importmap">
      {
        "imports": {
          "three": "https://unpkg.com/three@0.160.0/build/three.module.js",
          "three/addons/": "https://unpkg.com/three@0.160.0/examples/jsm/"
        }
      }
    </script>

    <script type="module" src="./viewer.js"></script>
  </body>
</html>
```

#### `viewer/styles.css`

```css
:root {
  --bg: #0b0f14;
  --card: rgba(15, 20, 28, 0.92);
  --text: #e9eef7;
  --muted: rgba(233, 238, 247, 0.75);
  --bar: rgba(233, 238, 247, 0.12);
  --barFill: rgba(0, 170, 255, 0.95);
  --radius: 14px;
}

html, body {
  margin: 0;
  height: 100%;
  background: var(--bg);
  color: var(--text);
  font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
}

#app { position: fixed; inset: 0; overflow: hidden; }

#vp-canvas {
  width: 100%;
  height: 100%;
  display: block;
  touch-action: none;
}

.overlay, .fallback {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 18px;
  box-sizing: border-box;
  background: rgba(0,0,0,0.25);
}

.card {
  width: min(520px, 100%);
  background: var(--card);
  border-radius: var(--radius);
  padding: 14px 14px 12px;
  box-sizing: border-box;
  backdrop-filter: blur(8px);
}

.title { font-weight: 700; font-size: 16px; margin-bottom: 8px; }
.status { font-size: 14px; color: var(--muted); margin-bottom: 10px; }

.progress {
  width: 100%;
  height: 10px;
  border-radius: 999px;
  background: var(--bar);
  overflow: hidden;
  margin-bottom: 10px;
}

.bar { height: 100%; width: 0%; background: var(--barFill); }

.preview {
  width: 100%;
  aspect-ratio: 16/9;
  border-radius: 10px;
  overflow: hidden;
  background: rgba(255,255,255,0.05);
  margin-bottom: 10px;
}

.preview img { width: 100%; height: 100%; object-fit: contain; display: none; }

.actions { display: flex; gap: 10px; }

.btn {
  flex: 1;
  padding: 10px 12px;
  border: 0;
  border-radius: 10px;
  background: rgba(255,255,255,0.08);
  color: var(--text);
  font-weight: 600;
  font-size: 14px;
}

.btn:active { transform: translateY(1px); }

.hint {
  margin-top: 10px;
  font-size: 12px;
  color: rgba(233,238,247,0.60);
  line-height: 1.35;
}

.hud {
  position: absolute;
  top: 10px;
  left: 10px;
  right: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  pointer-events: none;
}

.icon-btn {
  pointer-events: auto;
  width: 44px;
  height: 44px;
  border: 0;
  border-radius: 12px;
  font-size: 18px;
  background: rgba(0,0,0,0.35);
  color: var(--text);
}

.stats {
  pointer-events: none;
  font-size: 12px;
  color: rgba(233,238,247,0.8);
  background: rgba(0,0,0,0.20);
  padding: 8px 10px;
  border-radius: 12px;
}
```

#### `viewer/viewer.js`

Важные места:
- `GLTFLoader.setDRACOLoader()` и `GLTFLoader.setMeshoptDecoder()` включены, т.к. это требуется для загрузки соответствующих расширений. ?cite?turn21view0?  
- `DRACOLoader` назначается как рекомендуемый “один instance, переиспользовать”. ?cite?turn21view2?  

```javascript
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/addons/loaders/DRACOLoader.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';

const canvas = document.getElementById('vp-canvas');

const overlay = document.getElementById('overlay');
const statusText = document.getElementById('statusText');
const progressBar = document.getElementById('progressBar');
const previewImg = document.getElementById('previewImg');
const hintText = document.getElementById('hintText');
const retryBtn = document.getElementById('retryBtn');
const openBtn = document.getElementById('openBtn');

const hud = document.getElementById('hud');
const resetBtn = document.getElementById('resetBtn');
const statsEl = document.getElementById('stats');

const fallback = document.getElementById('fallback');

// Config: adjust if WP is not site root
const WP_API_BASE = '/wp-json/vp/v1';
const MAX_DEVICE_PIXEL_RATIO = 2;
const TRIANGLE_WARNING = 800000;

function hasWebGL() {
  try {
    const c = document.createElement('canvas');
    const gl = c.getContext('webgl2') || c.getContext('webgl');
    return !!gl;
  } catch { return false; }
}

function parseRoute() {
  // Supported:
  //  #/viewer/<job_id>
  //  #/viewer/file/<file_uuid>
  const hash = (location.hash || '').replace(/^#/, '');
  const parts = hash.split('/').filter(Boolean);
  if (parts.length >= 2 && parts[0] === 'viewer') {
    if (parts[1] === 'file' && parts[2]) {
      return { mode: 'file', fileId: parts[2] };
    }
    if (parts[1]) {
      return { mode: 'job', jobId: parts[1] };
    }
  }
  return null;
}

function setProgress(pct) {
  const v = Math.max(0, Math.min(100, pct|0));
  progressBar.style.width = v + '%';
}

function setPreview(url) {
  if (!url) return;
  previewImg.src = url;
  previewImg.style.display = 'block';
}

async function api(path, opts = {}) {
  const res = await fetch(WP_API_BASE + path, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    ...opts,
  });
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch {}
  if (!res.ok) {
    const msg = (json && (json.message || json.code)) ? (json.message || json.code) : text.slice(0, 200);
    throw new Error(`API ${res.status}: ${msg}`);
  }
  return json;
}

async function createJob(inputFileId) {
  const r = await api('/3d/jobs', {
    method: 'POST',
    body: JSON.stringify({ input_file_id: inputFileId }),
  });
  return r.job_id;
}

async function getJob(jobId) {
  return api(`/3d/jobs/${encodeURIComponent(jobId)}`, { method: 'GET' });
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function waitForJob(jobId) {
  let delay = 700; // ms
  while (true) {
    const j = await getJob(jobId);

    const st = j.status || 'unknown';
    const prog = (typeof j.progress === 'number') ? j.progress : 0;

    statusText.textContent = `Статус: ${st} • ${prog}%`;
    setProgress(prog);

    if (j.assets && j.assets.preview_png) {
      setPreview(j.assets.preview_png);
    }

    if (st === 'completed' && j.assets && j.assets.output_glb) {
      return j;
    }
    if (st === 'failed') {
      throw new Error(j.error || 'conversion_failed');
    }

    await sleep(delay);
    delay = Math.min(2500, Math.round(delay * 1.15));
  }
}

function debounce(fn, ms) {
  let t = null;
  return (...args) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
}

function computeTriangles(root) {
  let tris = 0;
  root.traverse((obj) => {
    if (!obj.isMesh) return;
    const geo = obj.geometry;
    if (!geo) return;
    if (geo.index) tris += geo.index.count / 3;
    else if (geo.attributes && geo.attributes.position) tris += geo.attributes.position.count / 3;
  });
  return Math.round(tris);
}

function frameToFit(camera, controls, box, fitOffset = 1.2) {
  const size = new THREE.Vector3();
  const center = new THREE.Vector3();
  box.getSize(size);
  box.getCenter(center);

  const maxSize = Math.max(size.x, size.y, size.z);
  const fitHeightDistance = maxSize / (2 * Math.atan((Math.PI * camera.fov) / 360));
  const fitWidthDistance = fitHeightDistance / camera.aspect;
  const distance = fitOffset * Math.max(fitHeightDistance, fitWidthDistance);

  const direction = controls.target.clone()
    .sub(camera.position)
    .normalize()
    .multiplyScalar(-distance);

  controls.target.copy(center);
  camera.near = distance / 100;
  camera.far = distance * 100;
  camera.updateProjectionMatrix();

  camera.position.copy(center).add(direction);
  controls.update();
}

async function loadGLB(url) {
  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0x0b0f14);

  const camera = new THREE.PerspectiveCamera(45, 1, 0.01, 1000);
  camera.position.set(0, 0.5, 1.2);

  const renderer = new THREE.WebGLRenderer({ canvas, antialias: false, alpha: false, powerPreference: 'high-performance' });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, MAX_DEVICE_PIXEL_RATIO));
  renderer.setSize(window.innerWidth, window.innerHeight, false);

  const controls = new OrbitControls(camera, canvas);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;
  controls.rotateSpeed = 0.5;
  controls.zoomSpeed = 0.8;
  controls.panSpeed = 0.7;
  controls.screenSpacePanning = false;

  const hemi = new THREE.HemisphereLight(0xffffff, 0x223344, 0.9);
  scene.add(hemi);

  const dir = new THREE.DirectionalLight(0xffffff, 0.9);
  dir.position.set(2, 3, 2);
  scene.add(dir);

  const loader = new GLTFLoader();

  const dracoLoader = new DRACOLoader();
  // Для production — хостить decoders локально (WP static).
  dracoLoader.setDecoderPath('https://unpkg.com/three@0.160.0/examples/jsm/libs/draco/');
  loader.setDRACOLoader(dracoLoader);

  loader.setMeshoptDecoder(MeshoptDecoder);

  const started = performance.now();

  const gltf = await new Promise((resolve, reject) => {
    loader.load(
      url,
      (g) => resolve(g),
      (e) => {
        if (e && e.total) {
          const pct = Math.round((e.loaded / e.total) * 100);
          statusText.textContent = `Загрузка модели: ${pct}%`;
        } else {
          statusText.textContent = 'Загрузка модели…';
        }
      },
      (err) => reject(err),
    );
  });

  const root = gltf.scene || gltf.scenes?.[0];
  if (!root) throw new Error('no_scene_in_gltf');

  scene.add(root);

  const box = new THREE.Box3().setFromObject(root);
  frameToFit(camera, controls, box, 1.25);

  const tris = computeTriangles(root);
  const loadMs = Math.round(performance.now() - started);

  statsEl.textContent = `triangles: ${tris.toLocaleString()} • load: ${loadMs}ms`;
  if (tris > TRIANGLE_WARNING) {
    hintText.textContent = `?? Модель тяжёлая для мобильных (? ${tris.toLocaleString()} треуг.). Возможны лаги.`;
  }

  hud.style.display = 'flex';
  resetBtn.onclick = () => frameToFit(camera, controls, box, 1.25);

  const onResize = debounce(() => {
    const w = window.innerWidth;
    const h = window.innerHeight;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }, 120);

  window.addEventListener('resize', onResize, { passive: true });
  window.addEventListener('orientationchange', onResize, { passive: true });

  let stop = false;
  function animate() {
    if (stop) return;
    controls.update();
    renderer.render(scene, camera);
    requestAnimationFrame(animate);
  }
  animate();

  return () => { stop = true; dracoLoader.dispose(); };
}

async function main() {
  if (!hasWebGL()) {
    fallback.style.display = 'grid';
    overlay.style.display = 'none';
    return;
  }

  const route = parseRoute();
  if (!route) {
    statusText.textContent = 'Нет маршрута. Открой #/viewer/<job_id> или #/viewer/file/<file_id>';
    setProgress(0);
    return;
  }

  retryBtn.onclick = () => location.reload();

  try {
    let jobId = null;

    if (route.mode === 'file') {
      statusText.textContent = 'Создаём задачу…';
      jobId = await createJob(route.fileId);
      location.hash = `#/viewer/${jobId}`;
    } else {
      jobId = route.jobId;
    }

    statusText.textContent = 'Ожидаем конвертацию…';
    setProgress(1);

    const job = await waitForJob(jobId);

    openBtn.style.display = 'block';
    openBtn.onclick = async () => {
      openBtn.disabled = true;
      statusText.textContent = 'Загружаем в viewer…';
      try {
        await loadGLB(job.assets.output_glb);
        overlay.style.display = 'none';
      } catch (e) {
        statusText.textContent = 'Ошибка загрузки GLB';
        hintText.textContent = String(e && e.message ? e.message : e);
        retryBtn.style.display = 'block';
      }
    };

    await openBtn.onclick();

  } catch (e) {
    statusText.textContent = 'Ошибка';
    hintText.textContent = String(e && e.message ? e.message : e);
    retryBtn.style.display = 'block';
    setProgress(100);
  }
}

main();
```

## Smoke test

Smoke test делает ровно то, что нужно для MVP:
- создаёт job через WP endpoint,
- опрашивает статус,
- вытаскивает `output_file_id` и проверяет доступность `assets.output_glb` через HEAD/GET.

### Файл `3d_smoke_test.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

# 3D smoke test for VP (WordPress shell + Directus + Redis + Converter)
#
# Requires:
#   - curl
#   - jq
#
# Env vars:
#   WP_BASE_URL             e.g. https://app.example.com
#   VP_DX_AT                value of vp_dx_at cookie (Directus access token)
#   INPUT_FILE_ID           Directus directus_files.id (UUID)
#   DIRECTUS_PUBLIC_URL     e.g. https://directus.example.com (optional; defaults to WP_BASE_URL)
#   DIRECTUS_SERVICE_TOKEN  optional; if assets require auth, will be used as Bearer header for HEAD/GET
#
# Example:
#   WP_BASE_URL="https://example.com" VP_DX_AT="..." INPUT_FILE_ID="uuid" ./3d_smoke_test.sh

WP_BASE_URL="${WP_BASE_URL:-}"
INPUT_FILE_ID="${INPUT_FILE_ID:-}"
DIRECTUS_PUBLIC_URL="${DIRECTUS_PUBLIC_URL:-$WP_BASE_URL}"
VP_DX_AT="${VP_DX_AT:-}"
DIRECTUS_SERVICE_TOKEN="${DIRECTUS_SERVICE_TOKEN:-}"

if [[ -z "$WP_BASE_URL" ]]; then echo "WP_BASE_URL required" >&2; exit 1; fi
if [[ -z "$INPUT_FILE_ID" ]]; then echo "INPUT_FILE_ID required" >&2; exit 1; fi

API_CREATE="$WP_BASE_URL/wp-json/vp/v1/3d/jobs"
API_STATUS_BASE="$WP_BASE_URL/wp-json/vp/v1/3d/jobs"

cookie_file="$(mktemp)"
cleanup() { rm -f "$cookie_file"; }
trap cleanup EXIT

if [[ -n "$VP_DX_AT" ]]; then
  echo "$(echo "$WP_BASE_URL" | sed -E 's~https?://~~' | cut -d/ -f1)  TRUE  /  TRUE  2147483647  vp_dx_at  $VP_DX_AT" > "$cookie_file"
fi

echo "[1/4] Create job..."
create_resp="$(curl -sS \
  -X POST \
  -H 'Content-Type: application/json' \
  --cookie "$cookie_file" \
  --data "{\"input_file_id\":\"$INPUT_FILE_ID\"}" \
  "$API_CREATE")"

job_id="$(echo "$create_resp" | jq -r '.job_id // empty')"
if [[ -z "$job_id" || "$job_id" == "null" ]]; then
  echo "Create failed: $create_resp" >&2
  exit 1
fi
echo "job_id=$job_id"

echo "[2/4] Poll status..."
status=""
output_file=""
preview_file=""
output_url=""
for i in $(seq 1 180); do
  resp="$(curl -sS --cookie "$cookie_file" "$API_STATUS_BASE/$job_id")"
  status="$(echo "$resp" | jq -r '.status // empty')"
  prog="$(echo "$resp" | jq -r '.progress // 0')"
  output_file="$(echo "$resp" | jq -r '.output_file // empty')"
  preview_file="$(echo "$resp" | jq -r '.preview_file // empty')"
  output_url="$(echo "$resp" | jq -r '.assets.output_glb // empty')"

  echo "t=$i status=$status progress=$prog output_file=${output_file:-} preview=${preview_file:-}"

  if [[ "$status" == "completed" ]]; then
    break
  fi
  if [[ "$status" == "failed" ]]; then
    err="$(echo "$resp" | jq -r '.error // empty')"
    echo "Job failed: $err" >&2
    exit 1
  fi
  sleep 2
done

if [[ "$status" != "completed" ]]; then
  echo "Timeout waiting for completion" >&2
  exit 1
fi

if [[ -z "$output_file" || -z "$output_url" ]]; then
  echo "No output file/url in job response" >&2
  exit 1
fi

echo "[3/4] Check asset availability (HEAD)..."
auth_header=()
if [[ -n "$DIRECTUS_SERVICE_TOKEN" ]]; then
  auth_header=(-H "Authorization: Bearer $DIRECTUS_SERVICE_TOKEN")
fi

head_code="$(curl -sS -o /dev/null -w "%{http_code}" -I "${auth_header[@]}" "$output_url")"
echo "HEAD $output_url => $head_code"
if [[ "$head_code" -lt 200 || "$head_code" -ge 400 ]]; then
  echo "Asset not accessible via HEAD (code $head_code)" >&2
  exit 1
fi

echo "[4/4] Download first bytes (GET range) ..."
range_code="$(curl -sS -o /dev/null -w "%{http_code}" "${auth_header[@]}" -H "Range: bytes=0-31" "$output_url" || true)"
echo "GET range => $range_code"
echo "OK"
```

