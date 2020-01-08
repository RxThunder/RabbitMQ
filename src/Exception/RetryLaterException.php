<?php

declare(strict_types=1);

/*
 * This file is part of the Thunder micro CLI framework.
 * (c) Jérémy Marodon <marodon.jeremy@gmail.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RxThunder\RabbitMQ\Exception;

use RxThunder\Core\Model\DataModel;
use RxThunder\Core\Model\Exception\DataModelException;

class RetryLaterException extends DataModelException
{
    /**
     * The delay to wait before retrying the message (milliseconds).
     */
    private int $delay;

    public function __construct(DataModel $data_model, ?\Throwable $previous = null, int $delay = 2 * 60 * 1000, string $message = '', int $code = 0)
    {
        parent::__construct($data_model, $previous, $message, $code);
        $this->delay = $delay;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }
}
