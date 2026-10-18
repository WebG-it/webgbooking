#!/usr/bin/env python3
"""
Build the WebG Booking package from src/ (works on Windows, macOS and Linux, no PowerShell needed).

  python tools/build.py            -> dist/pkg_webgbooking-<version>.zip  (+ prints the sha256)
  python tools/build.py --xml      -> also rewrites updates-pkg.xml (version, download URL, sha256)

The children (com_webgbooking, plg_system_webgbooking, plg_task_webgbookingreminders) are zipped into
src/pkg_webgbooking/packages/ (git-ignored) and then wrapped by the package. Zip entry names always use "/".
The package version is read from src/pkg_webgbooking/pkg_webgbooking.xml: bump it there first.
"""
import hashlib
import os
import re
import sys
import zipfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SRC = os.path.join(ROOT, "src")
CHILDREN = ["com_webgbooking", "plg_system_webgbooking", "plg_task_webgbookingreminders"]
FIXED_TIME = (2026, 10, 18, 0, 0, 0)


def zip_dir(src_dir, dest_zip, skip=None):
    """Zip the CONTENT of src_dir (no wrapping folder), sorted, with fixed timestamps (reproducible)."""
    skip = skip or []
    entries = []
    for base, dirs, files in os.walk(src_dir):
        dirs.sort()
        for f in sorted(files):
            full = os.path.join(base, f)
            rel = os.path.relpath(full, src_dir).replace(os.sep, "/")
            if any(re.fullmatch(p, rel) for p in skip):
                continue
            entries.append((rel, full))
    with zipfile.ZipFile(dest_zip, "w", zipfile.ZIP_DEFLATED) as z:
        for rel, full in entries:
            info = zipfile.ZipInfo(rel, FIXED_TIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            with open(full, "rb") as fh:
                z.writestr(info, fh.read())


def manifest_version(path):
    m = re.search(r"<version>([^<]+)</version>", open(path, encoding="utf-8").read())
    return m.group(1).strip()


def main():
    pkg_dir = os.path.join(SRC, "pkg_webgbooking")
    packages = os.path.join(pkg_dir, "packages")
    os.makedirs(packages, exist_ok=True)

    for child in CHILDREN:
        zip_dir(os.path.join(SRC, child), os.path.join(packages, child + ".zip"))

    version = manifest_version(os.path.join(pkg_dir, "pkg_webgbooking.xml"))

    # Everything that carries a version must agree, or the build stops (the asset cache-buster stayed on an old
    # number for two releases because nothing checked it).
    plugin = open(os.path.join(SRC, "plg_system_webgbooking", "src", "Extension", "Webgbooking.php"), encoding="utf-8").read()
    asset = re.search(r"const ASSET_VER = '([^']+)'", plugin).group(1)
    wrong = {"ASSET_VER": asset} if asset != version else {}
    for child, manifest in (("com_webgbooking", "com_webgbooking.xml"), ("plg_system_webgbooking", "webgbooking.xml"), ("plg_task_webgbookingreminders", "webgbookingreminders.xml")):
        v = manifest_version(os.path.join(SRC, child, manifest))
        if v != version:
            wrong[child] = v
    if re.search(r"\$wgbVer\s*=\s*'[0-9]", open(os.path.join(SRC, "plg_system_webgbooking", "yootheme", "elements", "booking", "templates", "template.php"), encoding="utf-8").read()):
        wrong["template.php wgbVer"] = "hard-coded"
    if wrong:
        sys.exit("version mismatch: package is %s but %s" % (version, wrong))
    dist = os.path.join(ROOT, "dist")
    os.makedirs(dist, exist_ok=True)
    out = os.path.join(dist, "pkg_webgbooking-%s.zip" % version)
    zip_dir(pkg_dir, out)

    sha = hashlib.sha256(open(out, "rb").read()).hexdigest()
    print("package %s\nfile    %s\nsha256  %s" % (version, out, sha))

    if "--xml" in sys.argv:
        p = os.path.join(ROOT, "updates-pkg.xml")
        x = open(p, encoding="utf-8").read()
        x = re.sub(r"<version>[^<]+</version>", "<version>%s</version>" % version, x, count=1)
        x = re.sub(r"(dist/pkg_webgbooking-)[0-9.]+(\.zip)", r"\g<1>%s\g<2>" % version, x)
        x = re.sub(r"<sha256>[^<]+</sha256>", "<sha256>%s</sha256>" % sha, x)
        x = x.replace("(component + system plugin)", "(component + system plugin + reminders task)")
        open(p, "w", encoding="utf-8", newline="").write(x)
        print("updates-pkg.xml updated")


if __name__ == "__main__":
    main()
