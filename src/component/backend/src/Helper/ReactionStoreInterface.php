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
	 * @return array<int,array{id:int,asset_id:int,enabled:int,created_by:int,email:string}>  indexado por id; los inexistentes no
	 *         aparecen. `email` es el del comentarista de un comentario de INVITADO ('' si no lo tiene): 0.7.1, para saber si es «propio»
	 *         por el email verificado de la sesion.
	 */
	public function comments(array $ids): array;

	/**
	 * @param   int[]  $commentIds
	 *
	 * @return array<int,int[]>  comentario => tipos que ha marcado ESTE usuario
	 */
	public function userTypes(int $userId, array $commentIds): array;

	/**
	 * Contadores de me gusta y no me gusta (el favorito es privado y NO se cuenta aqui). 0.7.1: NO cuenta los me gusta / no me gusta que
	 * el autor del comentario (created_by) se haya dado a si mismo (no se pueden hacer, pero pudo quedar uno antiguo).
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

	/**
	 * 0.7.1: bloquea la fila del comentario hasta el final de la transaccion (SELECT ... FOR UPDATE) para que las reacciones del MISMO
	 * comentario se ejecuten una detras de otra, sea cual sea el nivel de aislamiento (en READ COMMITTED no hay bloqueos de hueco y
	 * un me gusta y un no me gusta simultaneos podian quedar los dos). Se llama dentro de transaction().
	 *
	 * @return bool  false si el comentario ya no existe
	 */
	public function lockComment(int $commentId): bool;
}
