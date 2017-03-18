<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Model\Build;

use PHPCI\Model\Build\RemoteGitBuild;

/**
* Gitlab Build Model
* @author       André Cianfarani <a.cianfarani@c2is.fr>
* @package      PHPCI
* @subpackage   Core
*/
class GitlabBuild extends RemoteGitBuild
{

    /**
    * Get link to commit from another source (i.e. Github)
    */
    public function getCommitLink()
    {
        $domain = $this->getProject()->getAccessInformation("domain");
        return 'http://' . $domain . '/' . $this->getProject()->getReference() . '/commit/' . $this->getCommitId();
    }

    /**
    * Get link to branch from another source (i.e. Github)
    */
    public function getBranchLink()
    {
        $domain = $this->getProject()->getAccessInformation("domain");
        return 'http://' . $domain . '/' . $this->getProject()->getReference() . '/tree/' . $this->getBranch();
    }

    /**
     * Get link to specific file (and line) in a the repo's branch
     */
    public function getFileLinkTemplate()
    {
        return sprintf(
            'http://%s/%s/blob/%s/{FILE}#L{LINE}',
            $this->getProject()->getAccessInformation("domain"),
            $this->getProject()->getReference(),
            $this->getCommitId()
        );
    }

    /**
    * Get the URL to be used to clone this remote repository.
    */
    protected function getCloneUrl()
    {
        $protocol = $this->getProject()->getAccessInformation('protocol');
        $user = $this->getProject()->getAccessInformation("user");
        $domain = $this->getProject()->getAccessInformation("domain");
        $port = $this->getProject()->getAccessInformation('port');
        $reference = $this->getProject()->getReference();

        $protocol .= '://';
        if (!empty($user)) { $user .= '@'; }
        if (!empty($port)) { $port = ':' . $port . '/'; } else { $port = ':'; }

        $url = sprintf('%s%s%s%s%s.git', $protocol, $user, $domain, $port, $reference);

        return $url;
    }
}
