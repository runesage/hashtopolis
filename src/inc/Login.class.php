<?php

use DBA\QueryFilter;
use DBA\Session;
use DBA\User;
use DBA\Factory;

/**
 * Handles the login sessions
 *
 * @author Sein
 */
class Login {
  private $user  = null;
  private $valid = false;
  /** @var Session $session */
  private $session = null;
  
  private static $instance = null;
  
  public function setUser($user) {
    $this->user = $user;
  }
  
  /**
   * Get an instance of the Login class
   * @return Login
   */
  public static function getInstance() {
    if (self::$instance == null) {
      self::$instance = new Login();
    }
    return self::$instance;
  }
  
  /**
   * Creates a Login-Instance and checks automatically if there is a session
   * running. It updates the session lifetime again up to the session limit.
   */
  private function __construct() {
    $this->user = null;
    $this->session = null;
    $this->valid = false;
    if (isset($_COOKIE['session'])) {
      $session = $_COOKIE['session'];
      $filter1 = new QueryFilter(Session::SESSION_KEY, $session, "=");
      $filter2 = new QueryFilter(Session::IS_OPEN, "1", "=");
      $filter3 = new QueryFilter(Session::LAST_ACTION_DATE, time() - 100000, ">");
      $check = Factory::getSessionFactory()->filter([Factory::FILTER => [$filter1, $filter2, $filter3]]);
      if ($check === null || sizeof($check) == 0) {
        setcookie("session", false, time() - 600); //delete invalid or old cookie
        return;
      }
      $s = $check[0];
      $this->user = Factory::getUserFactory()->get($s->getUserId());
      if ($this->user !== null) {
        if ($s->getLastActionDate() < time() - $this->user->getSessionLifetime()) {
          setcookie("session", false, time() - 600); //delete invalid or old cookie
          return;
        }
        $this->valid = true;
        $this->session = $s;
        $s->setLastActionDate(time());
        Factory::getSessionFactory()->update($s);
        setcookie("session", $s->getSessionKey(), time() + $this->user->getSessionLifetime(), "", "", false, true);
      }
    }
  }
  
  /**
   * Returns true if the user currently is loggedin with a valid session
   */
  public function isLoggedin() {
    return $this->valid;
  }
  
  /**
   * Logs the current user out and closes his session
   */
  public function logout() {
    $this->session->setIsOpen(0);
    Factory::getSessionFactory()->update($this->session);
    setcookie("session", false, time() - 600);
  }
  
  /**
   * Returns the uID of the currently logged in user, if the user is not logged
   * in, the uID will be -1
   */
  public function getUserID() {
    return $this->user->getId();
  }
  
  public function getUser() {
    return $this->user;
  }
  
  /**
   * Executes a login with given username and password (plain)
   *
   * @param string $username username of the user to be logged in
   * @param string $password password which was entered on login form
   * @param string $otp OTP login field
   * @return true on success and false on failure
   */
  public function login($username, $password, $otp = NULL) {
    /****** Check password ******/
    if ($this->valid == true) {
      return false;
    }
    $filter = new QueryFilter(User::USERNAME, $username, "=");
    
    $check = Factory::getUserFactory()->filter([Factory::FILTER => $filter]);
    if ($check === null || sizeof($check) == 0) {
      return false;
    }
    $user = $check[0];
    
    if ($user->getIsValid() != 1) {
      return false;
    }
    else if (!Encryption::passwordVerify($password, $user->getPasswordSalt(), $user->getPasswordHash())) {
      Util::createLogEntry(DLogEntryIssuer::USER, $user->getId(), DLogEntry::WARN, "Failed login attempt due to wrong password!");
      
      $payload = new DataSet(array(DPayloadKeys::USER => $user));
      NotificationHandler::checkNotifications(DNotificationType::USER_LOGIN_FAILED, $payload);
      return false;
    }
    $this->user = $user;
    /****** End check password ******/
    
    /***** Check Yubikey *****/
    if ($user->getYubikey() == true && Util::isYubikeyEnabled() && sizeof(SConfig::getInstance()->getVal(DConfig::YUBIKEY_ID)) != 0 && sizeof(SConfig::getInstance()->getVal(DConfig::YUBIKEY_KEY) != 0)) {
      $keyId = substr($otp, 0, 12);
      
      if (strtoupper($user->getOtp1()) != strtoupper($keyId) && strtoupper($user->getOtp2()) != strtoupper($keyId) && strtoupper($user->getOtp3()) != strtoupper($keyId) && strtoupper($user->getOtp4()) != strtoupper($keyId)) {
        Util::createLogEntry(DLogEntryIssuer::USER, $user->getId(), DLogEntry::WARN, "Failed Yubikey login attempt due to wrong keyId!");
        return false;
      }
      
      $useHttps = true;
      $urlOTP = SConfig::getInstance()->getVal(DConfig::YUBIKEY_URL);
      if (!empty($urlOTP) && $_url = parse_url($urlOTP)) {
        if ($_url['scheme'] == "http") {
          $useHttps = false;
        }
        $urlPart = $_url['host'];
        if (!empty($_url['port'])) {
          $urlPart .= ':' . $_url['port'];
        }
        $urlPart .= $_url['path'];
      }
      
      $yubi = new Auth_Yubico(SConfig::getInstance()->getVal(DConfig::YUBIKEY_ID), SConfig::getInstance()->getVal(DConfig::YUBIKEY_KEY), $useHttps, true);
      
      if (!empty($urlPart)) {
        $yubi->addURLpart($urlPart);
      }
      $auth = $yubi->verify($otp);
      
      if (PEAR::isError($auth)) {
        Util::createLogEntry(DLogEntryIssuer::USER, $user->getId(), DLogEntry::WARN, "Failed login attempt due to wrong Yubikey OTP!");
        return false;
      }
    }
    else if ($user->getYubikey() == true && Util::isYubikeyEnabled()) {
      return false;
    }
    /****** End check Yubikey ******/
    
    // At this point the user is authenticated successfully, so the session can be created.
    
    /****** Create session ******/
    $startTime = time();
    $s = new Session(null, $this->user->getId(), $startTime, $startTime, 1, $this->user->getSessionLifetime(), "");
    $s = Factory::getSessionFactory()->save($s);
    if ($s === null) {
      return false;
    }
    $sessionKey = Encryption::sessionHash($s->getId(), $startTime, $user->getEmail());
    $s->setSessionKey($sessionKey);
    Factory::getSessionFactory()->update($s);
    
    $this->user->setLastLoginDate(time());
    Factory::getUserFactory()->update($this->user);
    
    $this->valid = true;
    Util::createLogEntry(DLogEntryIssuer::USER, $user->getId(), DLogEntry::INFO, "Successful login!");
    setcookie("session", "$sessionKey", time() + $this->user->getSessionLifetime(), "", "", false, true);
    return true;
  }
}




