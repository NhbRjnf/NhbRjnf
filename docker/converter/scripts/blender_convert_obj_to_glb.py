import sys
import os
import bpy

# Usage:
# blender -b -P blender_convert_obj_to_glb.py -- input.obj output.glb

argv = sys.argv
if "--" not in argv:
    raise SystemExit("Missing -- args")
idx = argv.index("--")
inp = argv[idx+1]
outp = argv[idx+2]

bpy.ops.wm.read_factory_settings(use_empty=True)

ext = os.path.splitext(inp)[1].lower()
if ext == ".obj":
    bpy.ops.wm.obj_import(filepath=inp)
elif ext == ".stl":
    bpy.ops.import_mesh.stl(filepath=inp)
else:
    raise SystemExit(f"Unsupported import for blender: {ext}")

# Basic scene normalization
for obj in bpy.context.scene.objects:
    if obj.type == 'MESH':
        obj.select_set(True)
        bpy.context.view_layer.objects.active = obj
        bpy.ops.object.shade_smooth()

# Export GLB
bpy.ops.export_scene.gltf(
    filepath=outp,
    export_format='GLB',
    export_yup=True,
    export_apply=True
)
print("OK")
