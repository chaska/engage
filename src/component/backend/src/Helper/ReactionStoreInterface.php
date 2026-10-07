<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.7.0): lo que la logica de reacciones necesita del almacenamiento. Lo implementa ReactionStore (base de
 * datos real) y las pruebas una version en memoria.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

interface ReactionStoreInterface
{
	/**
	 * @param   int[]  $ids
	 *
	 * @return array<int,array{id:int,asset_id:int,enabled:int,created_by:int}>  indexado por id; los inexistentes no aparecen
	 */
	public function comments(array $ids): array;

	/**
	 * @param   int[]  $commentIds
	 *
	 * @return array<int,int[]>  comentario => tipos que ha marcado ESTE usuario
	 */
	public function userTypes(int $userId, array $commentIds): array;

	/**
	 * Contadores de me gusta y no me gusta (el favorito es privado y NO se cuenta aqui).
	 *
	 * @param   int[]  $commentIds
	 *
	 * @return array<int,array<int,int>>  comentario => [tipo => cuantos]
	 */
	public function counts(array $commentIds): array;

	public function add(int $commentId, int $userId, int $type, string $created): void;

	public function remove(int $commentId, int $userId, int $type): void;

	/** Ejecuta $fn dentro de una transaccion (rollback si lanza). */
	public function transaction(callable $fn): void;
}
