<?php
/** Cliente HTTP minimo con tarro de cookies propio (un "actor": invitado, registrado, editor...). */
class Cliente
{
	public string $base;
	public string $jar;
	public array $ultimo = [];

	public function __construct(string $base, string $dirTmp, string $nombre)
	{
		$this->base = rtrim($base, '/');
		$this->jar  = rtrim($dirTmp, '/') . '/cj-' . preg_replace('/\W/', '', $nombre) . '.txt';
		@unlink($this->jar);
	}

	/** @return array{code:int,loc:string,headers:string,body:string,ms:int,err:string} */
	public function req(string $metodo, string $ruta, array $campos = [], array $cabeceras = [], bool $crudo = false): array
	{
		$url = preg_match('#^https?://#', $ruta) ? $ruta : $this->base . $ruta;
		$ch  = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60,
			CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabeceras, CURLOPT_USERAGENT => 'joomla-live-tests/1.0',
		]);
		if ($metodo === 'POST') {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $crudo ? ($campos[0] ?? '') : http_build_query($campos));
		}
		$t0   = microtime(true);
		$resp = curl_exec($ch);
		$ms   = (int) ((microtime(true) - $t0) * 1000);
		$err  = curl_error($ch);
		$hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		$hdr  = substr((string) $resp, 0, $hs);
		$loc  = preg_match('/^Location:\s*(.+?)\r?$/mi', $hdr, $m) ? trim($m[1]) : '';

		return $this->ultimo = ['code' => $code, 'loc' => $loc, 'headers' => $hdr, 'body' => (string) substr((string) $resp, $hs), 'ms' => $ms, 'err' => $err];
	}

	public function get(string $ruta, array $cab = []): array { return $this->req('GET', $ruta, [], $cab); }
	public function post(string $ruta, array $campos, array $cab = []): array { return $this->req('POST', $ruta, $campos, $cab); }

	/** Token de formulario (campo oculto de 32 hex con valor 1). */
	public static function token(string $html): string
	{
		return preg_match('/name="([0-9a-f]{32})" value="1"/', $html, $m) ? $m[1] : '';
	}

	public function login(string $user, string $pass): bool
	{
		// El token sale del modulo de acceso de la portada (la vista de login redirige con 301 a la URL SEF).
		$p = $this->get('/');
		$t = self::token($p['body']);
		$this->post('/index.php?option=com_users&task=user.login', ['username' => $user, 'password' => $pass, 'return' => base64_encode($this->base . '/'), $t => 1]);
		$q = $this->get('/');

		return $q['code'] === 200 && stripos($q['body'], 'user.logout') !== false;
	}

	public function loginAdmin(string $user, string $pass): bool
	{
		$p = $this->get('/administrator/index.php');
		$t = self::token($p['body']);
		$this->post('/administrator/index.php', ['username' => $user, 'passwd' => $pass, 'option' => 'com_login', 'task' => 'login', 'return' => base64_encode($this->base . '/administrator/index.php'), $t => 1]);
		$q = $this->get('/administrator/index.php?option=com_cpanel');

		return $q['code'] === 200 && stripos($q['body'], 'task=logout') !== false || stripos($q['body'], 'com_login&amp;task=logout') !== false;
	}
}
