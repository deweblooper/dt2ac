<?php

/*
 * This library is free software, and it is part of the Active Collab SDK project. Check LICENSE for details.
 *
 * (c) A51 doo <info@activecollab.com>
 */

namespace ActiveCollab\SDK\Exceptions;

/**
 * @package ActiveCollab\SDK\Exceptions
 */
class ListAccounts extends Authentication
{
    /**
     * @param string          $message
     * @param \Exception|null $previous
     */
    public function __construct($message = null, $previous = null)
    {
        if (empty($message)) {
            $message = 'Failed to list user accounts';
        }

        parent::__construct($message, $previous);
    }
}
