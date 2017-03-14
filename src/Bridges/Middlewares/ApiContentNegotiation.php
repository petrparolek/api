<?php

namespace Contributte\Api\Bridges\Middlewares;

use Contributte\Api\Bridges\Middlewares\Negotiation\IRequestNegotiator;
use Contributte\Api\Bridges\Middlewares\Negotiation\IResponseNegotiator;
use Contributte\Api\Http\Request\ApiRequest;
use Contributte\Api\Http\Response\ApiResponse;
use Exception;

class ApiContentNegotiation
{

	// Attributes in ServerRequestInterface
	const ATTR_SKIP = 'C-Negotiation-Skip';
	const ATTR_SKIP_REQUEST = 'C-Negotiation-Skip-Request';
	const ATTR_SKIP_RESPONSE = 'C-Negotiation-Skip-Response';

	/** @var IRequestNegotiator[] */
	protected $requestNegotiators = [];

	/** @var IResponseNegotiator[] */
	protected $responseNegotiators = [];

	/**
	 * @param array $negotiators
	 */
	public function __construct(array $negotiators = [])
	{
		$this->addNegotiations($negotiators);
	}

	/**
	 * SETTERS *****************************************************************
	 */

	/**
	 * @param IRequestNegotiator[] $negotiators
	 * @return void
	 */
	public function addRequestNegotiations(array $negotiators)
	{
		foreach ($negotiators as $negotiator) {
			$this->addRequestNegotiation($negotiator);
		}
	}

	/**
	 * @param IRequestNegotiator $negotiator
	 * @return void
	 */
	public function addRequestNegotiation(IRequestNegotiator $negotiator)
	{
		$this->requestNegotiators[] = $negotiator;
	}

	/**
	 * @param IResponseNegotiator[] $negotiators
	 * @return void
	 */
	public function addResponseNegotiations(array $negotiators)
	{
		foreach ($negotiators as $negotiator) {
			$this->addResponseNegotiation($negotiator);
		}
	}

	/**
	 * @param IResponseNegotiator $negotiator
	 * @return void
	 */
	public function addResponseNegotiation(IResponseNegotiator $negotiator)
	{
		$this->responseNegotiators[] = $negotiator;
	}

	/**
	 * @param array $negotiators
	 * @return void
	 */
	public function addNegotiations(array $negotiators)
	{
		foreach ($negotiators as $negotiator) {
			if ($negotiator instanceof IRequestNegotiator) {
				$this->addRequestNegotiation($negotiator);
			}
			if ($negotiator instanceof IResponseNegotiator) {
				$this->addResponseNegotiation($negotiator);
			}
		}
	}

	/**
	 * API - INVOKING **********************************************************
	 */

	/**
	 * @param ApiRequest $request
	 * @param ApiResponse $response
	 * @param callable $next
	 * @return ApiResponse
	 */
	public function __invoke(ApiRequest $request, ApiResponse $response, callable $next)
	{
		// Should we skip negotiation?
		if ($request->getPsr7()->getAttribute(self::ATTR_SKIP, FALSE) === TRUE) {
			return $next($request, $response);
		}

		// 1) Request negotiation
		if ($request->getPsr7()->getAttribute(self::ATTR_SKIP_REQUEST, FALSE) !== TRUE) {
			$request = $this->negotiateRequest($request, $response);
		}

		// 2) Pass to next invoker
		try {
			$response = $next($request, $response);
		} catch (Exception $e) {
			$response = $this->negotiateException($e, $request, $response);
		}

		// 3) Response negotiation
		if ($request->getPsr7()->getAttribute(self::ATTR_SKIP_RESPONSE, FALSE) !== TRUE) {
			$response = $this->negotiateResponse($request, $response);
		}

		return $response;
	}

	/**
	 * NEGOTIATION *************************************************************
	 */

	/**
	 * @param ApiRequest $request
	 * @param ApiResponse $response
	 * @return ApiRequest
	 */
	protected function negotiateRequest(ApiRequest $request, ApiResponse $response)
	{
		// Early return in case of no negotiators
		if (!$this->requestNegotiators) return $request;

		foreach ($this->requestNegotiators as $negotiator) {
			// Pass to negotiator and check return value
			$negotiated = $negotiator->negotiateRequest($request, $response);

			// If it's not NULL, we have an ApiRequest
			if ($negotiated !== NULL) {
				return $negotiated;
			}
		}

		return $request;
	}

	/**
	 * @param ApiRequest $request
	 * @param ApiResponse $response
	 * @return ApiResponse
	 */
	protected function negotiateResponse(ApiRequest $request, ApiResponse $response)
	{
		// Early return in case of no negotiators
		if (!$this->responseNegotiators) return $response;

		foreach ($this->responseNegotiators as $negotiator) {
			// Pass to negotiator and check return value
			$negotiated = $negotiator->negotiateResponse($request, $response);

			// If it's not NULL, we have an ApiResponse
			if ($negotiated !== NULL) {
				return $negotiated;
			}
		}

		return $response;
	}

	/**
	 * @param Exception $exception
	 * @param ApiRequest $request
	 * @param ApiResponse $response
	 * @return ApiResponse
	 */
	protected function negotiateException(Exception $exception, ApiRequest $request, ApiResponse $response)
	{
		$response->setData([
			'error' => $exception->getMessage(),
			'code' => $exception->getCode(),
		]);

		$code = $exception->getCode();
		$response->setStatus($code < 200 || $code > 504 ? 404 : $code);

		return $response;
	}

}
