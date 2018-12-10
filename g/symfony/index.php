<?php

use App\Kernel;
use Bref\Bridge\Psr7\RequestFactory;
use Bref\Bridge\Symfony\SymfonyAdapter;
use Bref\Http\LambdaResponse;
use Symfony\Component\Debug\Debug;

ini_set('display_errors', '1');
error_reporting(E_ALL);

$lambdaRuntimeApi = getenv('AWS_LAMBDA_RUNTIME_API');

require __DIR__ . '/vendor/autoload.php';

Debug::enable();

$kernel = new Kernel('prod', false);
$kernel->boot();
$symfonyAdapter = new SymfonyAdapter($kernel);

// This is a blocking HTTP call until an event is available
[$event, $invocationId] = waitForEventFromLambdaApi($lambdaRuntimeApi);

$request = RequestFactory::fromLambdaEvent($event);
$response = $symfonyAdapter->handle($request);
$lambdaResponse = LambdaResponse::fromPsr7Response($response);

signalSuccessToLambdaApi($lambdaRuntimeApi, $invocationId, $lambdaResponse);

function waitForEventFromLambdaApi(string $lambdaRuntimeApi): ?array
{
    $ch = curl_init("http://$lambdaRuntimeApi/2018-06-01/runtime/invocation/next");

    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    $invocationId = '';

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$invocationId) {
        if (! preg_match('/:\s*/', $header)) {
            return strlen($header);
        }

        [$name, $value] = preg_split('/:\s*/', $header, 2);

        if (strtolower($name) == 'lambda-runtime-aws-request-id') {
            $invocationId = trim($value);
        }

        return strlen($header);
    });

    $body = '';

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body) {
        $body .= $chunk;

        return strlen($chunk);
    });

    curl_exec($ch);

    if (curl_error($ch)) {
        die('Failed to fetch next Lambda invocation: ' . curl_error($ch) . "\n");
    }

    if ($invocationId == '') {
        die('Failed to determine Lambda invocation ID');
    }

    curl_close($ch);

    if (! $body) {
        die("Empty Lambda invocation response\n");
    }

    $event = json_decode($body, true);

    if (! array_key_exists('requestContext', $event)) {
        fail($lambdaRuntimeApi, $invocationId, 'Event is not an API Gateway request');
        return null;
    }

    return [$event, $invocationId];
}

function signalSuccessToLambdaApi(string $lambdaRuntimeApi, string $invocationId, LambdaResponse $response)
{
    $ch = curl_init("http://$lambdaRuntimeApi/2018-06-01/runtime/invocation/$invocationId/response");

    $response_json = $response->toJson();

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $response_json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($response_json),
    ]);

    curl_exec($ch);
    curl_close($ch);
}

function fail($lambdaRuntimeApi, $invocationId, $errorMessage)
{
    $ch = curl_init("http://$lambdaRuntimeApi/2018-06-01/runtime/invocation/$invocationId/response");

    $response = [];

    $response['statusCode'] = 500;
    $response['body'] = $errorMessage;

    $response_json = json_encode($response);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $response_json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($response_json),
    ]);

    curl_exec($ch);
    curl_close($ch);
}
