<?php

namespace FiveStones\GmpReporting\Concerns;

use Google_Client;
use Illuminate\Database\Eloquent\Model;

trait HasGoogleClient
{
    /**
     * @var  Google_Client
     */
    protected $googleClient;

    /**
     * If the API token is stored in an Eloquent model, this trait accepts the model
     * and can invoke the $methodToGetClient to get the Google_Client when needed
     *
     * @var  \Illuminate\Database\Eloquent\Model
     */
    protected $googleApiTokenModel;

    /**
     * Method to call for getting Google_Client from an Eloquent model
     *
     * @var  string
     */
    protected $googleApiTokenModelGetClientMethod;

    /**
     * Method to call for setting the new access token, i.e. refreshed,
     * to the source Eloquent model
     *
     * @var  string
     */
    protected $googleApiTokenModelUpdateTokenMethod;

    /**
     * Flag to identify the access token is refresh or not
     *
     * @var boolean
     */
    protected $isAccessTokenRefreshed = false;

    /**
     * Returns an initialized Google_Client with active acess token
     *
     * @return \Google_Client|null
     */
    protected function getGoogleClient(): ?Google_Client
    {
        // obtain initialized client from model if class and method name are provded
        if (
            $this->googleApiTokenModel instanceof Model &&
            method_exists($this->googleApiTokenModel, $this->googleApiTokenModelGetClientMethod)
        ) {
            $this->googleApiTokenModel = $this->googleApiTokenModel->fresh();
            $this->googleClient = $this->googleApiTokenModel->{$this->googleApiTokenModelGetClientMethod}();
        }

        // obtain a fresh access token if the existing one is expired
        if (
            $this->googleClient instanceof Google_Client &&
            $this->googleClient->isAccessTokenExpired()
        ) {
            $freshAccessToken = $this->googleClient->fetchAccessTokenWithRefreshToken();

            // pass the fresh token to the model for saving
            if (
                $this->googleApiTokenModel instanceof Model &&
                method_exists($this->googleApiTokenModel, $this->googleApiTokenModelUpdateTokenMethod)
            ) {
                $this->googleApiTokenModel->{$this->googleApiTokenModelUpdateTokenMethod}($freshAccessToken);
            }
        }

        return $this->googleClient;
    }

    /**
     * Setter for Google_Client
     *
     * @param  \GoogleClient $client
     * @return object
     */
    public function setGoogleClient(Google_Client $client): self
    {
        $this->googleClient = $client;

        return $this;
    }

    /**
     * Setter for Eloquent model containing access token and methods to access the Google API client
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return object
     */
    public function setGoogleApiTokenModel(Model $model): self
    {
        $this->googleApiTokenModel = $model;

        return $this;
    }

    /**
     * Setter for method name to get Google client from the Eloquent model
     *
     * @param  string $method
     * @return object
     */
    public function setGoogleApiTokenModelGetClientMethod(string $method): self
    {
        $this->googleApiTokenModelGetClientMethod = $method;

        return $this;
    }

    /**
     * Setter for method name to update access token for the Eloquent model
     *
     * @param  string $method
     * @return object
     */
    public function setGoogleApiTokenModelUpdateTokenMethod(string $method): self
    {
        $this->googleApiTokenModelUpdateTokenMethod = $method;

        return $this;
    }
}
