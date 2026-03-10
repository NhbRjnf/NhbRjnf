import os
import sys
import math
import bpy
import numpy as np

if not hasattr(np, "bool"):
    np.bool = np.bool_

# Usage:
# blender -b -P blender_render_preview.py -- input.glb output.png

argv = sys.argv
if "--" not in argv:
    raise SystemExit("Missing -- args")
idx = argv.index("--")
inp = argv[idx+1]
outp = argv[idx+2]

bpy.ops.wm.read_factory_settings(use_empty=True)

# Import GLB
bpy.ops.import_scene.gltf(filepath=inp)

scene = bpy.context.scene
scene.render.engine = 'BLENDER_EEVEE'
scene.render.filepath = outp
scene.render.image_settings.file_format = 'PNG'
scene.render.resolution_x = 1024
scene.render.resolution_y = 1024

# Camera
cam_data = bpy.data.cameras.new("Camera")
cam = bpy.data.objects.new("Camera", cam_data)
scene.collection.objects.link(cam)
scene.camera = cam

# Light
light_data = bpy.data.lights.new(name="Light", type='AREA')
light = bpy.data.objects.new(name="Light", object_data=light_data)
scene.collection.objects.link(light)
light.location = (3, -3, 3)
light_data.energy = 2000

# Fit camera to bounds (simple heuristic)
cam.location = (2.5, -2.5, 2.0)
cam.rotation_euler = (math.radians(60), 0, math.radians(45))

bpy.ops.render.render(write_still=True)
print("OK")
