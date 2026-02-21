<?php
/**
 * Migration Repository Interface
 *
 * Composite interface that combines progress, checkpoint, and token repository
 * contracts into a single contract for the migration repository implementation.
 *
 * @package Bricks2Etch\Repositories\Interfaces
 * @since 1.0.0
 */

namespace Bricks2Etch\Repositories\Interfaces;

use Bricks2Etch\Repositories\Interfaces\Progress_Repository_Interface;
use Bricks2Etch\Repositories\Interfaces\Checkpoint_Repository_Interface;
use Bricks2Etch\Repositories\Interfaces\Token_Repository_Interface;

/**
 * Interface Migration_Repository_Interface
 *
 * Composite interface extending Progress_Repository_Interface,
 * Checkpoint_Repository_Interface, and Token_Repository_Interface.
 * All 24 methods are inherited from the three sub-interfaces.
 */
interface Migration_Repository_Interface extends Progress_Repository_Interface, Checkpoint_Repository_Interface, Token_Repository_Interface {
}
