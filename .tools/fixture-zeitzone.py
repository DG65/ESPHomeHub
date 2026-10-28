# Erzeugt .tools/fixture-zeitzone.json mit aioesphomeapi (MIT): POSIX-String je
# IANA-Zeitzone (timezone.iana_to_posix_tz) und die GetTimeResponse-Bytes, wie
# aioesphomeapi sie sendet (connection._send_get_time_response).
#
# Aufruf: python3 .tools/fixture-zeitzone.py   (braucht: pip install aioesphomeapi)
import json, os
from aioesphomeapi import api_pb2 as p
from aioesphomeapi.timezone import iana_to_posix_tz
from aioesphomeapi.connection import _build_parsed_tz_proto

EPOCHE = 1793200000
IANA = ["Europe/Berlin", "Europe/Vienna", "Europe/Zurich", "Europe/London", "America/New_York", "America/Sao_Paulo",
        "Asia/Kolkata", "Australia/Lord_Howe", "Pacific/Chatham", "Africa/Casablanca", "UTC"]
EXTRA = ["EST5EDT,J60,J300", "EST5EDT,60,300/1:30", "<+0545>-5:45", "XYZ", ""]

faelle = []
for iana in IANA:
    faelle.append({"iana": iana, "posix": iana_to_posix_tz(iana)})
for s in EXTRA:
    faelle.append({"iana": "", "posix": s})
for f in faelle:
    r = p.GetTimeResponse(epoch_seconds=EPOCHE, timezone=f["posix"])
    tz = _build_parsed_tz_proto(f["posix"])
    if tz is not None:
        r.parsed_timezone.CopyFrom(tz)
    f["bytes"] = r.SerializeToString().hex()
ziel = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixture-zeitzone.json")
with open(ziel, "w") as fh:
    json.dump({"quelle": "aioesphomeapi timezone/posix_tz", "epoche": EPOCHE, "faelle": faelle}, fh, indent=1)
for f in faelle:
    print(f"{f['iana'] or '-':22} {f['posix']}")
