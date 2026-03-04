import os
import json
import time
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone

from directus import DirectusClient
from vp_queue import blocking_pop

LOG_LEVEL = os.getenv("VP_3D_LOG_LEVEL", "INFO").upper()

def log(msg: str):
    print(f"[converter] {msg}", flush=True)

def run(cmd, timeout=1800):
    log("RUN: " + " ".join(cmd))
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout, text=True)
    log(p.stdout[-4000:])
    if p.returncode != 0:
        raise RuntimeError(f"Command failed ({p.returncode}): {' '.join(cmd)}")

def now_iso():
    return datetime.now(timezone.utc).isoformat()

def gltf_optimize(glb_path: str):
    if os.getenv("VP_3D_GLTF_TRANSFORM_OPTIMIZE", "1") != "1":
        return
    # Safe MVP optimize (no draco/meshopt by default)
    out = glb_path.replace(".glb", ".opt.glb")
    run(["gltf-transform", "optimize", glb_path, out])
    shutil.move(out, glb_path)

def main():
    dx = DirectusClient()
    log("Worker started. Waiting for jobs...")

    while True:
        item = blocking_pop(timeout_sec=30)
        if not item:
            continue
        _, job_id = item
        log(f"JOB: {job_id}")

        tmpdir = tempfile.mkdtemp(prefix="vp3d_")
        try:
            dx.patch_job(job_id, {"status": "processing", "progress": 1, "error": None, "updated_at": now_iso()})
            job = dx.get_job(job_id)
            input_file_id = job.get("input_file")
            if not input_file_id:
                raise RuntimeError("Job has no input_file")

            inp_path = os.path.join(tmpdir, "input.bin")
            dx.patch_job(job_id, {"progress": 5, "updated_at": now_iso()})
            dx.download_file_to(input_file_id, inp_path)

            size_mb = os.path.getsize(inp_path) / (1024 * 1024)
            max_mb = float(os.getenv("VP_3D_MAX_INPUT_MB", "200"))
            if size_mb > max_mb:
                raise RuntimeError(f"Input too large: {size_mb:.1f}MB > {max_mb}MB")

            # Detect by magic name from Directus: fetch job meta? (MVP: trust extension-less input -> try STEP first)
            # Лучше: хранить original filename в job.meta; добавим позже.
            # Сейчас: пробуем STEP->OBJ через FreeCAD; если не вышло, пробуем как STL/OBJ (Blender).
            obj_path = os.path.join(tmpdir, "model.obj")
            glb_path = os.path.join(tmpdir, "model.glb")
            png_path = os.path.join(tmpdir, "preview.png")

            dx.patch_job(job_id, {"progress": 15, "updated_at": now_iso()})

            converted = False
            # Try FreeCAD import (STEP/IGES)
            try:
                step_path = os.path.join(tmpdir, "model.step")
                shutil.copy(inp_path, step_path)
                run(["freecadcmd", "/app/scripts/freecad_export_obj.py", step_path, obj_path], timeout=1800)
                converted = True
            except Exception as e:
                log(f"FreeCAD path failed (will fallback): {e}")

            dx.patch_job(job_id, {"progress": 35, "updated_at": now_iso()})

            if converted:
                run(["blender", "-b", "-P", "/app/scripts/blender_convert_obj_to_glb.py", "--", obj_path, glb_path], timeout=1800)
            else:
                # Fallback: assume STL (best effort)
                stl_path = os.path.join(tmpdir, "model.stl")
                shutil.copy(inp_path, stl_path)
                run(["blender", "-b", "-P", "/app/scripts/blender_convert_obj_to_glb.py", "--", stl_path, glb_path], timeout=1800)

            dx.patch_job(job_id, {"progress": 65, "updated_at": now_iso()})

            # Optimize GLB
            gltf_optimize(glb_path)

            dx.patch_job(job_id, {"progress": 80, "updated_at": now_iso()})

            # Preview render
            run(["blender", "-b", "-P", "/app/scripts/blender_render_preview.py", "--", glb_path, png_path], timeout=1800)

            dx.patch_job(job_id, {"progress": 90, "updated_at": now_iso()})

            # Upload to Directus
            out_file_id = dx.upload_file(glb_path, title=f"vp3d_{job_id}.glb")
            prev_file_id = dx.upload_file(png_path, title=f"vp3d_{job_id}.png")

            meta = {
                "input_file_id": input_file_id,
                "output_file_id": out_file_id,
                "preview_file_id": prev_file_id,
                "sizes": {
                    "input_bytes": os.path.getsize(inp_path),
                    "glb_bytes": os.path.getsize(glb_path),
                    "png_bytes": os.path.getsize(png_path),
                },
                "finished_at": now_iso(),
            }

            dx.patch_job(job_id, {
                "status": "completed",
                "progress": 100,
                "output_file": out_file_id,
                "preview_file": prev_file_id,
                "meta": meta,
                "completed_at": now_iso(),
                "updated_at": now_iso(),
            })

            log(f"DONE job={job_id} glb={out_file_id} png={prev_file_id}")

        except Exception as e:
            err = str(e)
            log(f"FAIL job={job_id}: {err}")
            try:
                dx.patch_job(job_id, {"status": "failed", "error": err, "updated_at": now_iso()})
            except Exception as e2:
                log(f"Also failed to patch job error: {e2}")
        finally:
            shutil.rmtree(tmpdir, ignore_errors=True)

if __name__ == "__main__":
    main()
