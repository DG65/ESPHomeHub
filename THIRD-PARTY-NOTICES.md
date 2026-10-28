# Hinweise zu Fremdlizenzen

ESPHomeHub selbst steht unter der PolyForm Noncommercial License 1.0.0 (siehe `LICENSE`).
Es enthält keinen fremden Quellcode. Folgende Werke unter der MIT-Lizenz dienten als Vorlage
bzw. als Prüfreferenz:

## aioesphomeapi

Nachrichtennummern und Feldnummern der ESPHome Native API (`api.proto`) sowie der Ablauf von
Rahmen und Verschlüsselung (`_frame_helper/noise.py`, `plain_text.py`) sind nach
https://github.com/esphome/aioesphomeapi in PHP nachgebaut.

```
MIT License

Copyright (c) 2018 Otto Winter

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## noiseprotocol

Der Prüfstand (`.tools/fixture-erzeugen.py`) zeichnet mit https://github.com/plizonczyk/noiseprotocol
eine Referenzsitzung auf, gegen die die PHP-Verschlüsselung byte-genau geprüft wird. Die
Bibliothek ist nicht Teil des Moduls.

```
MIT License

Copyright (c) 2017 Piotr Lizończyk

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
