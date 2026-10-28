# Erzeugt .tools/fixture-entitaeten.json mit den echten Protobuf-Klassen aus
# aioesphomeapi (api_pb2, MIT): Entitätsbeschreibungen, Zustände und die
# erwarteten Befehls-Bytes. Der PHP-Prüfstand muss jede Beschreibung richtig
# lesen und jeden Befehl byte-gleich bauen.
#
# Aufruf: python3 .tools/fixture-entitaeten.py   (braucht: pip install aioesphomeapi)
import json, os
from aioesphomeapi import api_pb2 as p



def nr(msg):
    # Nachrichtennummer aus option (id) der api.proto
    from aioesphomeapi.core import MESSAGE_TYPE_TO_PROTO
    for k, v in MESSAGE_TYPE_TO_PROTO.items():
        if v is type(msg):
            return k
    raise KeyError(type(msg).__name__)


def h(msg):
    return msg.SerializeToString().hex()


L = []   # Beschreibungen
Z = []   # Zustände
B = []   # Befehle


def liste(msg):
    L.append({"typ": nr(msg), "daten": h(msg), "name": msg.name})


def zustand(msg, erwartet):
    Z.append({"typ": nr(msg), "daten": h(msg), "key": msg.key, "erwartet": erwartet})


def befehl(key, teil, wert, msg):
    B.append({"key": key, "teil": teil, "wert": wert, "typ": nr(msg), "daten": h(msg)})


liste(p.ListEntitiesSensorResponse(key=1, name="Temperatur", unit_of_measurement="°C", accuracy_decimals=1, device_class="temperature"))
liste(p.ListEntitiesSensorResponse(key=2, name="Zähler", accuracy_decimals=-1))
liste(p.ListEntitiesBinarySensorResponse(key=3, name="Tür", device_class="door"))
liste(p.ListEntitiesTextSensorResponse(key=4, name="Version"))
liste(p.ListEntitiesSwitchResponse(key=5, name="Relais"))
liste(p.ListEntitiesButtonResponse(key=6, name="Neustart"))
liste(p.ListEntitiesNumberResponse(key=7, name="Sollwert", min_value=10, max_value=30, step=0.5, unit_of_measurement="°C"))
liste(p.ListEntitiesSelectResponse(key=8, name="Modus", options=["Aus", "Eco", "Komfort"]))
liste(p.ListEntitiesTextResponse(key=9, name="Notiz", max_length=64))
liste(p.ListEntitiesLightResponse(key=10, name="Licht", supported_color_modes=[p.COLOR_MODE_RGB, p.COLOR_MODE_COLOR_TEMPERATURE], min_mireds=153, max_mireds=500))
liste(p.ListEntitiesLightResponse(key=11, name="", supported_color_modes=[p.COLOR_MODE_ON_OFF]))
liste(p.ListEntitiesCoverResponse(key=12, name="Rollladen", supports_position=True, supports_stop=True, device_class="shutter"))
liste(p.ListEntitiesValveResponse(key=13, name="Ventil", supports_position=False, supports_stop=False))
liste(p.ListEntitiesFanResponse(key=14, name="Lüfter", supports_speed=True, supported_speed_count=3))
liste(p.ListEntitiesLockResponse(key=15, name="Tür", supports_open=True))
liste(p.ListEntitiesClimateResponse(key=16, name="Klima", supported_modes=[p.CLIMATE_MODE_OFF, p.CLIMATE_MODE_HEAT, p.CLIMATE_MODE_AUTO],
                                    visual_min_temperature=7, visual_max_temperature=28, visual_target_temperature_step=0.5, feature_flags=1 | 32))
liste(p.ListEntitiesEventResponse(key=17, name="Taster", event_types=["single", "double"]))
liste(p.ListEntitiesSirenResponse(key=18, name="Sirene"))

zustand(p.SensorStateResponse(key=1, state=21.47), {"e00000001": 21.5})
zustand(p.SensorStateResponse(key=1, missing_state=True), {})
zustand(p.SensorStateResponse(key=2, state=1234.0), {"e00000002": 1234.0})
zustand(p.BinarySensorStateResponse(key=3, state=True), {"e00000003": True})
zustand(p.TextSensorStateResponse(key=4, state="2026.10.1"), {"e00000004": "2026.10.1"})
zustand(p.SwitchStateResponse(key=5, state=True), {"e00000005": True})
zustand(p.NumberStateResponse(key=7, state=23.5), {"e00000007": 23.5})
zustand(p.SelectStateResponse(key=8, state="Komfort"), {"e00000008": "Komfort"})
zustand(p.TextStateResponse(key=9, state="Hallo Küche"), {"e00000009": "Hallo Küche"})
zustand(p.LightStateResponse(key=10, state=True, brightness=0.6, red=1.0, green=0.5, blue=0.0, color_temperature=250),
        {"e0000000a": True, "e0000000a_h": 60, "e0000000a_f": 0xFF8000, "e0000000a_k": 4000})
zustand(p.LightStateResponse(key=11, state=False), {"e0000000b": False})
zustand(p.CoverStateResponse(key=12, position=0.25, current_operation=p.COVER_OPERATION_IS_CLOSING), {"e0000000c": 25, "e0000000c_z": "Schließt"})
zustand(p.ValveStateResponse(key=13, position=1.0), {"e0000000d": True, "e0000000d_z": "Steht"})
zustand(p.FanStateResponse(key=14, state=True, speed_level=2), {"e0000000e": True, "e0000000e_g": 2})
zustand(p.LockStateResponse(key=15, state=p.LOCK_STATE_LOCKED), {"e0000000f_z": "Verriegelt", "e0000000f": True})
zustand(p.LockStateResponse(key=15, state=p.LOCK_STATE_JAMMED), {"e0000000f_z": "Blockiert"})
zustand(p.ClimateStateResponse(key=16, mode=p.CLIMATE_MODE_HEAT, current_temperature=20.3, target_temperature=21.5, action=p.CLIMATE_ACTION_HEATING),
        {"e00000010_m": 3, "e00000010": 21.5, "e00000010_t": 20.3, "e00000010_a": "Heizt"})
zustand(p.EventResponse(key=17, event_type="double"), {"e00000011": "double"})

befehl(5, "", True, p.SwitchCommandRequest(key=5, state=True))
befehl(5, "", False, p.SwitchCommandRequest(key=5, state=False))
befehl(6, "", 0, p.ButtonCommandRequest(key=6))
befehl(7, "", 23.5, p.NumberCommandRequest(key=7, state=23.5))
befehl(8, "", "Eco", p.SelectCommandRequest(key=8, state="Eco"))
befehl(9, "", "Grüße", p.TextCommandRequest(key=9, state="Grüße"))
befehl(10, "", True, p.LightCommandRequest(key=10, has_state=True, state=True))
befehl(10, "h", 40, p.LightCommandRequest(key=10, has_state=True, state=True, has_brightness=True, brightness=0.4))
befehl(10, "h", 0, p.LightCommandRequest(key=10, has_state=True, state=False))
befehl(10, "f", 0xFF0000, p.LightCommandRequest(key=10, has_state=True, state=True, has_rgb=True, red=1.0, green=0.0, blue=0.0))
befehl(10, "k", 4000, p.LightCommandRequest(key=10, has_state=True, state=True, has_color_temperature=True, color_temperature=250.0))
befehl(12, "", 30, p.CoverCommandRequest(key=12, has_position=True, position=0.3))
befehl(12, "s", 0, p.CoverCommandRequest(key=12, has_position=True, position=1.0))
befehl(12, "s", 1, p.CoverCommandRequest(key=12, stop=True))
befehl(12, "s", 2, p.CoverCommandRequest(key=12, has_position=True, position=0.0))
befehl(13, "s", 0, p.ValveCommandRequest(key=13, has_position=True, position=1.0))
befehl(14, "", False, p.FanCommandRequest(key=14, has_state=True, state=False))
befehl(14, "g", 3, p.FanCommandRequest(key=14, has_state=True, state=True, has_speed_level=True, speed_level=3))
befehl(15, "", True, p.LockCommandRequest(key=15, command=p.LOCK_LOCK))
befehl(15, "", False, p.LockCommandRequest(key=15, command=p.LOCK_UNLOCK))
befehl(15, "s", 2, p.LockCommandRequest(key=15, command=p.LOCK_OPEN))
befehl(16, "m", 6, p.ClimateCommandRequest(key=16, has_mode=True, mode=p.CLIMATE_MODE_AUTO))
befehl(16, "", 22.5, p.ClimateCommandRequest(key=16, has_target_temperature=True, target_temperature=22.5))

ziel = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixture-entitaeten.json")
with open(ziel, "w") as f:
    json.dump({"quelle": "aioesphomeapi api_pb2", "liste": L, "zustaende": Z, "befehle": B}, f, indent=1, ensure_ascii=False)
print("geschrieben:", ziel, len(L), "Beschreibungen,", len(Z), "Zustände,", len(B), "Befehle")
