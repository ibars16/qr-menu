<?php

namespace App\Service\TranslationEval;

/** The translation eval stops here: the pinned provider failed, ran out of quota, cut off or answered something unusable. */
final class TranslationEvalStopped extends \RuntimeException
{
}
