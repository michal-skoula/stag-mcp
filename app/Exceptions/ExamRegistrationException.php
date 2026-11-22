<?php

namespace App\Exceptions;

use Exception;

/**
 * A registration change that was refused before or by STAG. The message is
 * written for the MCP client and always says whether anything changed.
 */
class ExamRegistrationException extends Exception {}
