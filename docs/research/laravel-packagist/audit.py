"""Read pinned public source archives and run soda without executing package code."""

import concurrent.futures
import hashlib
import io
import json
import pathlib
import subprocess
import sys
import urllib.request
import zipfile

HERE = pathlib.Path(__file__).resolve().parent
OUTPUT = pathlib.Path(sys.argv[1]).resolve()
OUTPUT.mkdir(parents=True, exist_ok=True)


def download(url):
    request = urllib.request.Request(url, headers={"User-Agent": "soda-laravel-audit"})
    with urllib.request.urlopen(request, timeout=120) as response:
        return response.read()


def audit(spec):
    record = dict(spec)
    name = spec["name"].split("/")[1]
    root = OUTPUT / "sources" / name
    report = OUTPUT / (name + ".json")
    try:
        marker = root / ".soda-reference"
        if marker.exists() and marker.read_text() != spec["reference"]:
            raise ValueError("Cached source revision differs; use a fresh output directory")
        if not marker.exists() or marker.read_text() != spec["reference"]:
            archive = zipfile.ZipFile(io.BytesIO(download(spec["dist"])))
            root.mkdir(parents=True, exist_ok=True)
            for entry in archive.infolist():
                relative = pathlib.PurePosixPath(*pathlib.PurePosixPath(entry.filename).parts[1:])
                if not relative.parts or ".." in relative.parts or entry.is_dir():
                    continue
                target = root / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(archive.read(entry))
            marker.write_text(spec["reference"])

        # These distributions omit app/. Read the matching Git tag instead of linting a PHAR.
        if "source_supplement" in spec:
            root = OUTPUT / "git" / name
            if not root.exists():
                root.parent.mkdir(parents=True, exist_ok=True)
                subprocess.run(
                    ["git", "-c", "core.hooksPath=/dev/null", "clone", "--depth=1",
                     "--branch", spec["version"], "https://github.com/laravel/" + name + ".git", str(root)],
                    check=True, capture_output=True,
                )
            reference = subprocess.check_output(["git", "-C", str(root), "rev-parse", "HEAD"], text=True).strip()
            if reference != spec["reference"]:
                raise ValueError("Source tag changed; expected " + spec["reference"])

        report.unlink(missing_ok=True)
        result = subprocess.run(
            ["php", "-d", "memory_limit=1G", str(HERE / "scan.php"), str(root), str(report)],
            capture_output=True, text=True,
        )
        (OUTPUT / (name + ".log")).write_text(result.stdout + result.stderr)
        if result.returncode != 0:
            raise RuntimeError("Analysis failed; see " + name + ".log")
        data = json.loads(report.read_text())
        hashes = "".join(path + "\0" + sha + "\n" for path, sha in data["files"].items())
        record.update(status="analyzed", files=len(data["files"]), source_sha256=hashlib.sha256(hashes.encode()).hexdigest(),
                      diagnostics=len(data["violations"]))
    except Exception as error:
        record.update(status="unavailable", error=str(error))
    print(record["name"], record["status"], record.get("diagnostics", record.get("error", "")), flush=True)
    return record


specs = json.loads((HERE / "packages.json").read_text())["packages"]
with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
    records = list(pool.map(audit, specs))
(OUTPUT / "summary.json").write_text(json.dumps(records, indent=2) + "\n")
sys.exit(any(record["status"] != "analyzed" for record in records))
