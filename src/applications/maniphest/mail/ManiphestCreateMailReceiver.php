<?php

final class ManiphestCreateMailReceiver extends PhabricatorMailReceiver {

  public function isEnabled() {
    $app_class = 'PhabricatorApplicationManiphest';
    return PhabricatorApplication::isClassInstalled($app_class);
  }

  public function canAcceptMail(PhabricatorMetaMTAReceivedMail $mail) {
    $config_key = 'metamta.maniphest.public-create-email';
    $create_address = PhabricatorEnv::getEnvConfig($config_key);

    $reply = $mail->getHeader('in-reply-to');
    $is_forward = strpos($mail->getSubject(), 'Fwd:') !== false;
    if ($reply && !$is_forward) {
      return false;
    }

    $valid_addresses = array_merge($mail->getToAndCCAddresses(), $mail->getDeliveredToAddresses());
    foreach ($valid_addresses as $valid_address) {
      if ($this->matchAddresses($create_address, $valid_address)) {
        return true;
      }
    }

    return false;
  }

  public function loadSender(PhabricatorMetaMTAReceivedMail $mail) {
    try {
      // Try to load the sender normally.
      return parent::loadSender($mail);
    } catch (PhabricatorMetaMTAReceivedMailProcessingException $ex) {

      // If we failed to load the sender normally, use this special legacy
      // black magic.

      // TODO: Deprecate and remove this.

      $default_author_key = 'metamta.maniphest.default-public-author';
      $default_author = PhabricatorEnv::getEnvConfig($default_author_key);

      if (!strlen($default_author)) {
        throw $ex;
      }

      $user = id(new PhabricatorUser())->loadOneWhere(
        'username = %s',
        $default_author);

      if ($user) {
        return $user;
      }

      throw new PhabricatorMetaMTAReceivedMailProcessingException(
        MetaMTAReceivedMailStatus::STATUS_UNKNOWN_SENDER,
        pht(
          "Phabricator is misconfigured, the configuration key ".
          "'metamta.maniphest.default-public-author' is set to user ".
          "'%s' but that user does not exist.",
          $default_author));
    }
  }

  protected function processReceivedMail(
    PhabricatorMetaMTAReceivedMail $mail,
    PhabricatorUser $sender) {

    $task = new ManiphestTask();

    $task->setAuthorPHID($sender->getPHID());
    $task->setOriginalEmailSource($mail->getHeader('From'));
    $task->setPriority(ManiphestTaskPriority::PRIORITY_TRIAGE);

    $to_and_cc_addresses = $mail->getToAndCCAddresses();

    if (in_array("bugs@room77.com", $to_and_cc_addresses)) {
      $task->setOwnerPHID("PHID-USER-6xliut3v4jvoehton7wr");
      $task->setProjectPHIDs(array("PHID-PROJ-dkrujxbwzxbrqh66k5xh"));
      $task->setCCPHIDs(array_merge($task->getCCPHIDs(), array("PHID-USER-p323eqp6cnwqhuosklof")));
    }
    if (in_array("productideas@room77.com", $to_and_cc_addresses)) {
      $task->setProjectPHIDs(array("PHID-PROJ-ekgwxmbgw42bbalx4mhr"));
      $task->setCCPHIDs(array_merge($task->getCCPHIDs(), array("PHID-USER-dlxki6xmzpc3fbngyvx4")));
    }
    if (in_array("marketingideas@room77.com", $to_and_cc_addresses)) {
      $task->setProjectPHIDs(array("PHID-PROJ-nfxxikmojwd27e3qcaqi"));
      $task->setCCPHIDs(array_merge($task->getCCPHIDs(), array("PHID-USER-xxnynyohosao5iekrter")));
    }

    $editor = new ManiphestTransactionEditor();
    $editor->setActor($sender);
    $handler = $editor->buildReplyHandler($task);

    $handler->setActor($sender);
    $handler->setExcludeMailRecipientPHIDs(
      $mail->loadExcludeMailRecipientPHIDs());
    $handler->processEmail($mail);

    $mail->setRelatedPHID($task->getPHID());
  }

}
