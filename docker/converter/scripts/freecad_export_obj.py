import sys
import os

# FreeCAD headless script
# Usage: freecadcmd freecad_export_obj.py input.step output.obj

inp = sys.argv[1]
outp = sys.argv[2]

import FreeCAD
import ImportGui
import Mesh

doc = FreeCAD.newDocument()

# Load STEP/IGES
ext = os.path.splitext(inp)[1].lower()
if ext in [".step", ".stp"]:
    ImportGui.insert(inp, doc.Name)
elif ext in [".iges", ".igs"]:
    ImportGui.insert(inp, doc.Name)
else:
    raise RuntimeError(f"Unsupported for FreeCAD import: {ext}")

doc.recompute()

# Take all objects and mesh them
objs = doc.Objects
if not objs:
    raise RuntimeError("No objects imported")

mesh = Mesh.Mesh()
for o in objs:
    try:
        shape = o.Shape
        m = Mesh.Mesh(shape.tessellate(0.2))  # quality: lower -> more detail; tune later
        mesh.addMesh(m)
    except Exception:
        pass

if mesh.CountFacets == 0:
    raise RuntimeError("Meshing produced 0 facets")

mesh.write(outp)
print(f"OK facets={mesh.CountFacets}")
