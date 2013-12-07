<?php

final class PhragmentFragment extends PhragmentDAO
  implements PhabricatorPolicyInterface {

  protected $path;
  protected $depth;
  protected $latestVersionPHID;
  protected $viewPolicy;
  protected $editPolicy;

  private $latestVersion = self::ATTACHABLE;

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhragmentPHIDTypeFragment::TYPECONST);
  }

  public function getURI() {
    return '/phragment/fragment/'.$this->getID().'/';
  }

  public function getName() {
    return basename($this->path);
  }

  public function getFile() {
    return $this->assertAttached($this->file);
  }

  public function attachFile(PhabricatorFile $file) {
    return $this->file = $file;
  }

  public function isDirectory() {
    return $this->latestVersionPHID === null;
  }

  public function getLatestVersion() {
    if ($this->latestVersionPHID === null) {
      return null;
    }
    return $this->assertAttached($this->latestVersion);
  }

  public function attachLatestVersion(PhragmentFragmentVersion $version) {
    return $this->latestVersion = $version;
  }


/* -(  Updating  )  --------------------------------------------------------- */


  /**
   * Create a new fragment from a file.
   */
  public static function createFromFile(
    PhabricatorUser $viewer,
    PhabricatorFile $file = null,
    $path,
    $view_policy,
    $edit_policy) {

    $fragment = id(new PhragmentFragment());
    $fragment->setPath($path);
    $fragment->setDepth(count(explode('/', $path)));
    $fragment->setLatestVersionPHID(null);
    $fragment->setViewPolicy($view_policy);
    $fragment->setEditPolicy($edit_policy);
    $fragment->save();

    // Directory fragments have no versions associated with them, so we
    // just return the fragment at this point.
    if ($file === null) {
      return $fragment;
    }

    if ($file->getMimeType() === "application/zip") {
      $fragment->updateFromZIP($viewer, $file);
    } else {
      $fragment->updateFromFile($viewer, $file);
    }

    return $fragment;
  }


  /**
   * Set the specified file as the next version for the fragment.
   */
  public function updateFromFile(
    PhabricatorUser $viewer,
    PhabricatorFile $file) {

    $existing = id(new PhragmentFragmentVersionQuery())
      ->setViewer($viewer)
      ->withFragmentPHIDs(array($this->getPHID()))
      ->execute();
    $sequence = count($existing);

    $this->openTransaction();
      $version = id(new PhragmentFragmentVersion());
      $version->setSequence($sequence);
      $version->setFragmentPHID($this->getPHID());
      $version->setFilePHID($file->getPHID());
      $version->save();

      $this->setLatestVersionPHID($version->getPHID());
      $this->save();
    $this->saveTransaction();
  }

  /**
   * Apply the specified ZIP archive onto the fragment, removing
   * and creating fragments as needed.
   */
  public function updateFromZIP(
    PhabricatorUser $viewer,
    PhabricatorFile $file) {

    if ($file->getMimeType() !== "application/zip") {
      throw new Exception("File must have mimetype 'application/zip'");
    }

    // First apply the ZIP as normal.
    $this->updateFromFile($viewer, $file);

    // Ensure we have ZIP support.
    $zip = null;
    try {
      $zip = new ZipArchive();
    } catch (Exception $e) {
      // The server doesn't have php5-zip, so we can't do recursive updates.
      return;
    }

    $temp = new TempFile();
    Filesystem::writeFile($temp, $file->loadFileData());
    if (!$zip->open($temp)) {
      throw new Exception("Unable to open ZIP");
    }

    // Get all of the paths and their data from the ZIP.
    $mappings = array();
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $path = trim($zip->getNameIndex($i), '/');
      $stream = $zip->getStream($path);
      $data = null;
      // If the stream is false, then it is a directory entry.  We leave
      // $data set to null for directories so we know not to create a
      // version entry for them.
      if ($stream !== false) {
        $data = stream_get_contents($stream);
        fclose($stream);
      }
      $mappings[$path] = $data;
    }

    // Adjust the paths relative to this fragment so we can look existing
    // fragments up in the DB.
    $base_path = $this->getPath();
    $paths = array();
    foreach ($mappings as $p => $data) {
      $paths[] = $base_path.'/'.$p;
    }

    // FIXME: What happens when a child exists, but the current user
    // can't see it.  We're going to create a new child with the exact
    // same path and then bad things will happen.
    $children = id(new PhragmentFragmentQuery())
      ->setViewer($viewer)
      ->needLatestVersion(true)
      ->withPaths($paths)
      ->execute();
    $children = mpull($children, null, 'getPath');

    // Iterate over the existing fragments.
    foreach ($children as $full_path => $child) {
      $path = substr($full_path, strlen($base_path) + 1);
      if (array_key_exists($path, $mappings)) {
        if ($child->isDirectory() && $mappings[$path] === null) {
          // Don't create a version entry for a directory
          // (unless it's been converted into a file).
          continue;
        }

        // The file is being updated.
        $file = PhabricatorFile::newFromFileData(
          $mappings[$path],
          array('name' => basename($path)));
        $child->updateFromFile($viewer, $file);
      } else {
        // The file is being deleted.
        $child->deleteFile($viewer);
      }
    }

    // Iterate over the mappings to find new files.
    foreach ($mappings as $path => $data) {
      if (!array_key_exists($base_path.'/'.$path, $children)) {
        // The file is being created.  If the data is null,
        // then this is explicitly a directory being created.
        $file = null;
        if ($mappings[$path] !== null) {
          $file = PhabricatorFile::newFromFileData(
            $mappings[$path],
            array('name' => basename($path)));
        }
        PhragmentFragment::createFromFile(
          $viewer,
          $file,
          $base_path.'/'.$path,
          $this->getViewPolicy(),
          $this->getEditPolicy());
      }
    }
  }

  /**
   * Delete the contents of the specified fragment.
   */
  public function deleteFile(PhabricatorUser $viewer) {
    $existing = id(new PhragmentFragmentVersionQuery())
      ->setViewer($viewer)
      ->withFragmentPHIDs(array($this->getPHID()))
      ->execute();
    $sequence = count($existing);

    $this->openTransaction();
      $version = id(new PhragmentFragmentVersion());
      $version->setSequence($sequence);
      $version->setFragmentPHID($this->getPHID());
      $version->setFilePHID(null);
      $version->save();

      $this->setLatestVersionPHID($version->getPHID());
      $this->save();
    $this->saveTransaction();
  }


/* -(  Policy Interface  )--------------------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return $this->getViewPolicy();
      case PhabricatorPolicyCapability::CAN_EDIT:
        return $this->getEditPolicy();
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return false;
  }

  public function describeAutomaticCapability($capability) {
    return null;
  }

}