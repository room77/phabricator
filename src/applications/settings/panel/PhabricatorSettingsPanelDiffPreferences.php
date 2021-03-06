<?php

final class PhabricatorSettingsPanelDiffPreferences
  extends PhabricatorSettingsPanel {

  public function getPanelKey() {
    return 'diff';
  }

  public function getPanelName() {
    return pht('Diff Preferences');
  }

  public function getPanelGroup() {
    return pht('Application Settings');
  }

  public function processRequest(AphrontRequest $request) {
    $user = $request->getUser();
    $preferences = $user->loadPreferences();

    $pref_filetree = PhabricatorUserPreferences::PREFERENCE_DIFF_FILETREE;

    if ($request->isFormPost()) {
      $filetree = $request->getInt($pref_filetree);

      if ($filetree && !$preferences->getPreference($pref_filetree)) {
        $preferences->setPreference(
          PhabricatorUserPreferences::PREFERENCE_NAV_COLLAPSED,
          false);
      }

      $preferences->setPreference($pref_filetree, $filetree);

      $preferences->save();
      return id(new AphrontRedirectResponse())
        ->setURI($this->getPanelURI('?saved=true'));
    }

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel(pht('Show Filetree'))
          ->setName($pref_filetree)
          ->setValue($preferences->getPreference($pref_filetree))
          ->setOptions(
            array(
              0 => pht('Disable Filetree'),
              1 => pht('Enable Filetree'),
            ))
          ->setCaption(
            pht("When looking at a revision or commit, enable a sidebar ".
                "showing affected files. You can press %s to show or hide ".
                "the sidebar.",
                phutil_tag('tt', array(), 'f'))))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Save Preferences')));

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Diff Preferences'))
      ->setFormSaved($request->getBool('saved'))
      ->setForm($form);

    return array(
      $form_box,
    );
  }
}
