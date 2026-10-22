<?php

/**
 * LimeSurvey authentication plugin for AAI/Shibboleth (eduGAIN).
 *
 * The plugin trusts the attributes released by the Shibboleth Service Provider
 * and maps them onto a LimeSurvey user account.
 *
 * Attributes are expected to arrive as Apache environment variables
 * (eppn, nmec, cn, mail). Depending on the SAPI in use they are visible
 * either in $_SERVER (mod_php, Apache + proxy_fcgi) or only through getenv()
 * (nginx + php-fpm, where the default clear_env=yes strips the environment
 * from the worker unless the pool maps them explicitly). Both sources are
 * therefore consulted by getShibAttribute().
 *
 * @package   LimeSurvey
 * @author    UPDigital - mferraz@uporto.pt
 * @license   GPL-2.0-only
 */

class AuthAAI extends AuthPluginBase {

	protected $storage = 'DbStorage';
	static protected $description = 'Shibboleth authentication';
	static protected $name = 'AuthAAI';
	public $atributi = '';
	public $mail = '';
	public $displayName = '';
	protected $settings = array(
			'authsource' => array(
				'type' => 'string',
				'label' => 'Auth source'
				),
			'permission_create_survey' => array(
				'type' => 'checkbox',
				'label' => 'Permission create survey'
				)
			);

	public function init() {
		$this->subscribe('beforeLogin');
		$this->subscribe('newUserSession');
	}

	/**
	 * Reads a Shibboleth attribute released by the SP.
	 *
	 * The value is looked up in $_SERVER first and in the process environment
	 * afterwards, because the two are not both populated under every SAPI.
	 * Multi-valued attributes are normalised to their first value: mod_shib
	 * delivers them either as a PHP array or as a ";" separated string
	 * depending on the configuration.
	 *
	 * @param string $name Attribute name, e.g. 'eppn'
	 * @return string|null Attribute value, or null when not released
	 */
	protected function getShibAttribute($name) {
		if (isset($_SERVER[$name])) {
			$value = $_SERVER[$name];
		} else {
			$value = getenv($name);
			if ($value === false) {
				return null;
			}
		}

		if (is_array($value)) {
			$value = reset($value);
		}
		if (!is_string($value)) {
			return null;
		}

		if (strpos($value, ';') !== false) {
			$parts = explode(';', $value);
			$value = trim($parts[0]);
		}

		$value = trim($value);

		return ($value === '') ? null : $value;
	}

	/**
	 * Claims the authentication when the request carries AAI attributes.
	 *
	 * Calling setAuthPlugin() stops the event, which makes LimeSurvey skip
	 * the login form and delegate the authentication to newUserSession().
	 */
	public function beforeLogin() {
		$eppn = $this->getShibAttribute('eppn');
		if ($eppn === null) {
			return;
		}

		// nmec is the U.Porto staff/student number and the preferred
		// username. It is not released to every federated user, so fall back
		// to the eduPersonPrincipalName, which always is.
		$username = $this->getShibAttribute('nmec');
		if ($username === null) {
			$username = $eppn;
		}
		$this->setUsername($username);

		$this->displayName = (string) $this->getShibAttribute('cn');
		$this->mail = (string) $this->getShibAttribute('mail');
		if ($this->mail === '') {
			// The email column is mandatory on insert; eppn is a usable
			// fallback when the SP does not release 'mail'.
			$this->mail = $eppn;
		}

		$this->setAuthPlugin();
	}

	public function newUserSession() {
		// Do nothing if this user is not AuthAAI type
		$identity = $this->getEvent()->get('identity');
		if ($identity->plugin != 'AuthAAI') {
			return;
		}

		$sUser = $this->getUserName();
		$oUser = $this->api->getUserByName($sUser);

		if (is_null($oUser)) {

			if ($this->getShibAttribute('eppn') !== null) {

				// Create new user
				$oUser = new User;
				$oUser->users_name = $sUser;
				$oUser->setPassword(createPassword());
				// full_name is limited to 50 characters by the LS6 schema.
				$oUser->full_name = substr($this->displayName, 0, 50);
				$oUser->parent_id = 1;
				$oUser->lang = 'auto';
				$oUser->email = $this->mail;

				if ($oUser->save()) {
					if ($this->get('permission_create_survey', null, null, false)) {
						Permission::model()->setGlobalPermission($oUser->uid, 'surveys', array(
								'create_p',
								'read_p',
								'update_p',
								'delete_p',
								'import_p',
								'export_p'
								));
					}
					$this->setAuthSuccess($oUser);
					return;
				} else {
					$this->setAuthFailure(self::ERROR_USERNAME_INVALID);
					return;
				}
			}

			// No AAI attributes on this request: refuse the login instead of
			// silently leaving the result unset.
			$this->setAuthFailure(self::ERROR_UNKNOWN_IDENTITY);
			return;
		} else {

			$this->setAuthPlugin();
			$this->setAuthSuccess($oUser);

			return;
		}
	}
}
