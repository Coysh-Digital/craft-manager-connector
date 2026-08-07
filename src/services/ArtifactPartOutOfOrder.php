<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use RuntimeException;

/**
 * The platform is expecting a different part of the artifact than the one just sent.
 *
 * Not a rejection, which is why it is not an ordinary failure. It happens after a part whose response
 * never reached this site: the platform received it, this site did not learn so, and the retry landed
 * at an offset that would leave a hole. The platform answers with the part to continue from rather
 * than a bare refusal, and the entire value of that answer is that a dropped connection near the end
 * of a large upload costs one part instead of the whole database.
 *
 * A type of its own so the upload loop can act on it. Caught alongside a real transport failure it
 * would be counted against the retry budget, which would spend three attempts on the platform being
 * right and then give up.
 *
 * Carries only a part number. There is no path, no host and nothing else the platform could put here
 * that this site would act on - the destination is never a parameter, and that includes this.
 */
class ArtifactPartOutOfOrder extends RuntimeException
{
    public function __construct(public readonly int $resumeFromPart)
    {
        parent::__construct('the platform is expecting part ' . $resumeFromPart);
    }
}
