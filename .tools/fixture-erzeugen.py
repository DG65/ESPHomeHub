# Erzeugt .tools/fixture-noise.json: eine komplette verschlüsselte Sitzung,
# aufgezeichnet mit der Referenzbibliothek noiseprotocol (wie aioesphomeapi),
# beide Seiten mit festen ephemeren Schlüsseln. Der PHP-Prüfstand muss jedes
# Byte, das er sendet, exakt so erzeugen.
#
# Aufruf: python3 .tools/fixture-erzeugen.py   (braucht: pip install noiseprotocol)
import json, os, warnings
from noise.connection import NoiseConnection, Keypair

warnings.filterwarnings("ignore")
PSK = bytes(range(0x40, 0x60))
E_INIT = bytes(range(1, 33))
E_RESP = bytes(range(101, 133))
CLIENT = "Symcon Prüfstand"


def varint(v):
    o = b""
    while True:
        b = v & 0x7F
        v >>= 7
        o += bytes([b | 0x80 if v else b])
        if not v:
            return o


def text(f, s):
    s = s.encode()
    return varint(f << 3 | 2) + varint(len(s)) + s


def zahl(f, v):
    return varint(f << 3) + varint(v)


def inhalt(typ, daten):
    return typ.to_bytes(2, "big") + len(daten).to_bytes(2, "big") + daten


def rahmen(daten):
    return b"\x01" + len(daten).to_bytes(2, "big") + daten


def mk(init, e):
    c = NoiseConnection.from_name(b"Noise_NNpsk0_25519_ChaChaPoly_SHA256")
    c.set_as_initiator() if init else c.set_as_responder()
    c.set_psks(PSK)
    c.set_prologue(b"NoiseAPIInit\x00\x00")
    c.set_keypair_from_private_bytes(Keypair.EPHEMERAL, e)
    c.start_handshake()
    return c


i, r = mk(True, E_INIT), mk(False, E_RESP)
m1 = i.write_message()
r.read_message(m1)
m2 = r.write_message()
i.read_message(m2)

hello_req = text(1, CLIENT) + zahl(2, 1) + zahl(3, 14)
hello_resp = zahl(1, 1) + zahl(2, 19) + text(3, "2026.10.1") + text(4, "fixtur")
info = (text(2, "fixtur") + text(3, "AA:BB:CC:DD:EE:FF") + text(4, "2026.10.1") + text(5, "2026-10-28 12:00:00 +0100")
        + text(6, "esp32dev") + text(12, "Espressif") + text(13, "Fixtur Gerät"))

client_out = b"\x01\x00\x00" + rahmen(b"\x00" + m1)
server_hello = rahmen(b"\x01fixtur\x00AABBCCDDEEFF\x00")
server_hs = rahmen(b"\x00" + m2)
c_hello = rahmen(i.encrypt(inhalt(1, hello_req)))
s_hello = rahmen(r.encrypt(inhalt(2, hello_resp)))
c_info = rahmen(i.encrypt(inhalt(9, b"")))
s_info = rahmen(r.encrypt(inhalt(10, info)))
s_ping = rahmen(r.encrypt(inhalt(7, b"")))
c_pong = rahmen(i.encrypt(inhalt(8, b"")))
s_unbekannt = rahmen(r.encrypt(inhalt(25, b"\x0d\x11\x22\x33\x44\x15\x00\x00\xb0\x41")))

out = {
    "quelle": "noiseprotocol " + __import__("noise").__version__ if hasattr(__import__("noise"), "__version__") else "noiseprotocol",
    "psk_b64": __import__("base64").b64encode(PSK).decode(),
    "e_init": E_INIT.hex(), "client": CLIENT,
    "client_start": client_out.hex(), "server_hello": server_hello.hex(), "server_handshake": server_hs.hex(),
    "client_hello": c_hello.hex(), "server_hello_response": s_hello.hex(),
    "client_device_info_request": c_info.hex(), "server_device_info": s_info.hex(),
    "server_ping": s_ping.hex(), "client_pong": c_pong.hex(), "server_unbekannt": s_unbekannt.hex(),
    "handshake_hash": i.get_handshake_hash().hex(),
}
ziel = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixture-noise.json")
with open(ziel, "w") as f:
    json.dump(out, f, indent=1)
print("geschrieben:", ziel)
