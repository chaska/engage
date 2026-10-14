# Imprime las partes de texto (plano y HTML) de un mensaje .eml ya decodificadas. Uso: python3 -I leer-correo.py mensaje.eml
# 0.9.0: se lee en binario (un texto plano en 8bit con tildes se corrompia al leerlo como texto) y se escribe siempre en UTF-8.
import sys
import email
from email import policy

sys.stdout.reconfigure(encoding='utf-8', errors='replace')
with open(sys.argv[1], 'rb') as f:
    m = email.message_from_binary_file(f, policy=policy.default)
for p in m.walk():
    if p.get_content_type() in ('text/plain', 'text/html'):
        print('@@@PARTE ' + p.get_content_type() + '@@@')
        print(p.get_content())
