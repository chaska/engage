# Imprime las partes de texto (plano y HTML) de un mensaje .eml ya decodificadas. Uso: python3 -I leer-correo.py mensaje.eml
import sys
import email
from email import policy

with open(sys.argv[1], encoding='utf-8', errors='replace') as f:
    m = email.message_from_file(f, policy=policy.default)
for p in m.walk():
    if p.get_content_type() in ('text/plain', 'text/html'):
        print('@@@PARTE ' + p.get_content_type() + '@@@')
        print(p.get_content())
