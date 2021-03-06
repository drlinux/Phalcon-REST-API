<?php

namespace Fabs\Rest;

use Fabs\Rest\Constants\HttpHeaders;
use Fabs\Rest\Constants\HttpMethods;
use Fabs\Rest\Services\APIHandler;
use Fabs\Rest\Services\HttpStatusCodeHandler;
use Fabs\Rest\Services\PaginationHandler;
use Fabs\Rest\Services\TooManyRequestHandler;
use Phalcon\Cache\BackendInterface;
use Phalcon\Exception;
use Phalcon\Mvc\Micro;

/**
 * Class Application
 * @package Fabs\Rest
 *
 * @property APIHandler api_handler
 * @property Application application
 * @property HttpStatusCodeHandler status_code_handler
 * @property TooManyRequestHandler too_many_request_handler
 * @property BackendInterface cache
 * @property PaginationHandler pagination_handler
 */
class Application extends Micro
{
    /**
     * @var string[]
     */
    protected $request_data;
    /**
     * @var string[]
     */
    protected $expose_headers = [];

    public $ip_too_many_request_handler = null;

    public function __construct($di = null)
    {
        parent::__construct($di);

        $this->ip_too_many_request_handler = (new TooManyRequestHandler())->setPrefix('ip');
        $this->ip_too_many_request_handler->setSuffix($this->request->getClientAddress());
        $this->ip_too_many_request_handler->setDisable(true);

        $this->setBeforeAction();
        $this->setAfterAction();
        $this->handleErrors();
        $this->request->setHttpMethodParameterOverride(true);
    }

    public function handle($uri = null)
    {
        $this->checkDIRequirements();
        $this->api_handler->mount();
        parent::handle($uri);
    }

    /**
     * @return array
     */
    public function getRequestData()
    {
        if ($this->request_data == null) {
            $this->request_data = $this->request->getJsonRawBody(true);
            if ($this->request_data === false) {
                $this->request_data = [];
            }
        }
        return $this->request_data;
    }

    /**
     * @param string $header
     * @return Application
     */
    public function addExposeHeader($header)
    {
        $this->expose_headers[$header] = $header;
        return $this;
    }

    /**
     * @param string $header
     * @return Application
     */
    public function removeExposeHeader($header)
    {
        unset($this->expose_headers[$header]);
        return $this;
    }

    /**
     * @return string[]
     */
    public function getExposeHeaders()
    {
        return $this->expose_headers;
    }


    private function setBeforeAction()
    {
        $this->before(function () {
            $method = $this->request->getMethod();

            $this->ip_too_many_request_handler->increaseRequestCount();
            $this->ip_too_many_request_handler->setHeaders();
            if ($this->ip_too_many_request_handler->isLimitReached()) {
                $this->status_code_handler->tooManyRequest();
                return false;
            }

            $data = null;
            $is_data_required = $method == HttpMethods::POST
                || $method == HttpMethods::PUT
                || $method == HttpMethods::PATCH;

            if ($is_data_required) {
                $content_type = $this->request->getHeader(HttpHeaders::CONTENT_TYPE);
                if ($content_type != 'application/json') {
                    $this->status_code_handler->unsupportedMediaType([
                        HttpHeaders::CONTENT_TYPE => $content_type
                    ]);
                    return false;
                }

                $data = $this->getRequestData();
                if (count($data) == 0) {
                    if (json_last_error() != JSON_ERROR_NONE) {
                        $this->status_code_handler->badRequest();
                        return false;
                    } else {
                        $this->status_code_handler->unprocessableEntity();
                        return false;
                    }
                }
            }

            return true;
        });
    }

    private function setAfterAction()
    {
        $this->after(function () {
            if (!$this->response->isSent()) {
                $method = $this->request->getMethod();
                $content = $this->getReturnedValue();
                $is_not_modified = false;

                $exposed_headers = implode(', ', $this->getExposeHeaders());

                $this->response->setHeader(HttpHeaders::ACCESS_CONTROL_EXPOSE_HEADERS, $exposed_headers);
                if ($method == HttpMethods::GET) {
                    $this->pagination_handler->setHeaders();
                    $e_tag = strtoupper(md5(json_encode($content)));
                    $this->response->setHeader(HttpHeaders::ETAG, $e_tag);
                    $if_none_match = $this->request->getHeader(HttpHeaders::IF_NONE_MATCH);

                    if ($if_none_match == $e_tag) {
                        $is_not_modified = true;
                        $this->response->setNotModified();
                    }
                }

                if (!$is_not_modified) {
                    $this->response->setJsonContent(['status' => 'success', 'data' => $content]);
                }

                $this->response->send();
            }
        });
    }

    private function handleErrors()
    {
        $this->notFound(function () {
            $this->status_code_handler->notFound();
        });
    }

    private function checkDIRequirements()
    {
        $di = $this->getDI();
        foreach ($this->requiredServiceList() as $service_name => $service_type) {
            if (!$di->has($service_name)) {
                throw new Exception($service_name . ' service is required for di');
            }

            $service = $di->get($service_name);

            if (!($service instanceof $service_type)) {
                throw new Exception($service_name . ' must instanceof ' . $service_type);
            }
        }
    }

    private function requiredServiceList()
    {
        return [
            'api_handler' => APIHandler::class,
            'application' => Application::class,
            'status_code_handler' => HttpStatusCodeHandler::class,
            'too_many_request_handler' => TooManyRequestHandler::class,
            'cache' => BackendInterface::class,
            'pagination_handler' => PaginationHandler::class
        ];
    }
}