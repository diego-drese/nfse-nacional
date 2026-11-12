<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Exceptions;

use RuntimeException;

/** Certificado A1 ilegível (arquivo ou senha), vencido ou que não assina. */
final class CertificateException extends RuntimeException {}
