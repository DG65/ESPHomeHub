# ESPHomeHub

![Symcon](https://img.shields.io/badge/Symcon-PHP%20module-blue)
![Module version](https://img.shields.io/badge/Module-0.6.0-informational)
![Symcon version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue?logo=paypal)](https://paypal.me/DietmarGureth)

[ESPHome](https://esphome.io) devices directly in Symcon — through their own interface
(Native API), encrypted, without an MQTT server and without Home Assistant.

[Deutsche Fassung](README.md)

## What does this module do?

With a few lines of YAML, ESPHome turns an ESP32 or ESP8266 into a sensor, a relay, a light or a
Bluetooth proxy. In Symcon this used to require MQTT as a detour. ESPHomeHub talks to the device
the same way Home Assistant does: one TCP connection per device, the device describes itself,
changes arrive immediately.

**Status 0.6.0:** configurator with device discovery, encrypted connection, keep-alive,
reconnect, device info and all common entities as variables. The user interface follows the
language of Symcon (English or German). The Bluetooth proxy will be a separate module.

## Structure

| Instance | Task |
|---|---|
| **ESPHome configurator** (ESPHome-Konfigurator) | finds ESPHome devices on the network (mDNS) and creates them including the connection |
| **ESPHome device** (ESPHome-Gerät) | one ESPHome device on the network: connection, encryption, keep-alive, device info, entities as variables |
| Client Socket | below it, created together with the device (TCP to the device) |

## Setup

1. The device needs the API in its YAML, preferably with a key:
   ```yaml
   api:
     encryption:
       key: "…44 characters Base64…"
   ```
   New ESPHome projects already have the key. A password ("password:") no longer exists since
   ESPHome 2026.1.
2. In Symcon, create an instance **ESPHome configurator**. It lists all ESPHome devices on the
   network with friendly name, address, MAC, version, chip and whether a key is required.
3. Select the device, "Create": ESPHome device and Client Socket are created in one step.
4. Enter the key in the new instance and apply. The panel "Device" then shows "✅ Connected", the
   panel "Entities" everything the device offers.

Without the configurator it also works manually: create an instance **ESPHome device**, enter the
IP address or host name (e.g. `livingroom.local`) and the key.

The search uses mDNS and only reaches the same network segment. Devices behind VLAN boundaries,
VPN or in Docker without host network are entered in the configurator under "Manual addresses".
The configurator recognises existing instances by their MAC address, even if the IP has changed.

If Home Assistant runs in parallel, the device may hold both connections at the same time.

## Entities

After each connection the device describes what it can do. This results in variables below the
instance, with a matching presentation (unit, decimals, limits, plain text):

| ESPHome | Variables |
|---|---|
| sensor | number with unit |
| binary_sensor | yes/no with plain text per device class (opened/closed, motion, …) |
| text_sensor, event | text (for an event: the last one triggered) |
| switch | switch |
| button | button "Press" |
| number | slider or input field with limits |
| select | selection with the options of the device |
| text | input field |
| light | on/off, brightness, color, color temperature (depending on the light) |
| cover, valve | position in % (otherwise opened/closed), open/stop/close, movement |
| fan | on/off, speed |
| lock | locked, open (if supported), state |
| climate | mode, target temperature (or low/high), current temperature, action; depending on the device fan, swing, preset (including custom modes of the device), current and target humidity |

Not supported yet (named in the panel "Entities"): camera, media player, alarm panel, siren,
date/time, update, water heater, infrared/radio frequency.

Switchable variables can be operated in the visualization or set by script. The variable only
takes the value once the device confirms it; an invalid value or a lost connection leads to an
error message.

```php
RequestAction($variableID, true);                // e.g. switch on the variable "Relay"
IPS_RequestAction($id, 'e6d53a046_h', 40);       // light to 40 % (ident from the panel "Entities")
```

Fan and preset take the name of the mode as ESPHome knows it (`AUTO`, `QUIET`, `LOW`, `MEDIUM`,
`MIDDLE`, `HIGH`, … or `NONE`, `ECO`, `BOOST`, …), custom modes of the device their name from the
YAML; swing is a number (0 off, 1 both, 2 vertical, 3 horizontal). This fits, for example,
Mitsubishi air conditioners with the component MitsubishiCN105ESPHome; their vane positions come as
separate select entities.

```php
RequestAction($fanVariable, 'HIGH');             // climate fan to high
RequestAction($swingVariable, 2);                // vanes swing vertically
```

The ident of a variable is the `key` of the entity (`e` + 8 hex characters, partial variables with
a suffix). It stays the same as long as the `id` in the device YAML stays the same. If an entity is
removed, its variable and its archive remain and can be deleted manually.

## Behaviour

- The device sends a keep-alive every 60 seconds, ESPHomeHub answers.
- If nothing arrives for 150 seconds, or the connection is down, ESPHomeHub reconnects. This
  matters because an ESPHome device without a connected client restarts after `reboot_timeout`
  (default 15 minutes).
- A wrong key does not heal by itself: ESPHomeHub disconnects, shows the reason and only tries
  again after a change.
- The variable **Connected** (ident `Verbunden`) is a good trigger, e.g. for a notification when a
  device fails.
- If the device asks for the time (`time: platform: homeassistant`), ESPHomeHub answers with the
  time and the time zone of Symcon, including the daylight saving rules.

| Status | Meaning |
|---|---|
| 102 | connected |
| 104 | no address entered yet |
| 201 | key does not match the device (or is not a valid key) |
| 202 | encryption does not match: the device requires a key or has none |
| 203 | device not reachable, new attempt every 30 seconds |

```php
ESPH_GetDeviceInfo($id);   // JSON: name, mac, version, modell, anzeigename, hersteller, …
ESPH_GetEntities($id);     // JSON: all entities with type, name, capabilities; key = ident
ESPHC_GetDevices($id);     // configurator: devices found, as JSON
ESPH_Neuverbinden($id);    // reconnect
```

Function names, idents and JSON keys are German and stay the same in every language.

## Form: layout

In the ESPHome device, the panel "🪟 Form: layout" sets which of the sections Connection, Device
and Entities are expanded, collapsed or hidden when the form opens, and in which order they
appear. No checkbox means default. A hidden section keeps its settings and keeps working.
"↩️ Reset to default" resets everything; "Apply" saves.

## About the key

The key is stored as a hidden field in the settings of the instance. Symcon does not encrypt
stored settings. "Safe" here means: not readable in the form and not in the log. It is a setting
rather than an internal attribute so that it survives reloading the module in the module control.

## Questions and feedback

In the [ESPHomeHub thread in the Symcon forum](https://community.symcon.de/t/beta-modul-esphomehub-esphome-geraete-direkt-in-ip-symcon-ohne-mqtt-und-ohne-home-assistant/144807) (German, English welcome) or as a [GitHub issue](https://github.com/DG65/ESPHomeHub/issues).

## Foundations and thanks

- Protocol based on [aioesphomeapi](https://github.com/esphome/aioesphomeapi) (MIT), the library
  Home Assistant uses to talk to ESPHome devices. Message numbers from its `api.proto`, the
  encryption flow from `_frame_helper/noise.py`. The test bench compares every entity description,
  every state and every command with the protobuf classes of that library.
- Encryption `Noise_NNpsk0_25519_ChaChaPoly_SHA256` according to the
  [Noise specification](https://noiseprotocol.org/noise.html), rebuilt in PHP with sodium and
  checked byte by byte against the Python library
  [noiseprotocol](https://github.com/plizonczyk/noiseprotocol) (MIT). License notes in
  [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
- ESPHome is a project of the Open Home Foundation. ESPHomeHub is not an official ESPHome project.

## License

PolyForm Noncommercial 1.0.0 — private and non-commercial use is free, commercial use requires a
separate license from the rights holder (DG65). Donations are welcome:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
