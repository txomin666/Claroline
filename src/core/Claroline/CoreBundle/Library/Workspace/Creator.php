<?php

namespace Claroline\CoreBundle\Library\Workspace;

use Doctrine\ORM\EntityManager;
use Claroline\CoreBundle\Library\Security\RightManager\RightManager;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Entity\Role;
use Claroline\CoreBundle\Entity\Resource\AbstractResource;
use Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace;
use Claroline\CoreBundle\Entity\Resource\Directory;
use Claroline\CoreBundle\Entity\Rights\ResourceRights;
use Claroline\CoreBundle\Entity\Rights\WorkspaceRights;

class Creator
{
    private $entityManager;
    private $rightManager;
    private $roleRepo;

    public function __construct(EntityManager $em, RightManager $rm)
    {
        $this->entityManager = $em;
        $this->rightManager = $rm;
        $this->roleRepo = $this->entityManager->getRepository('ClarolineCoreBundle:Role');
    }

    /**
     * Creates a workspace.
     *
     * @param \Claroline\CoreBundle\Library\Workspace\Configuration $config
     * @param \Claroline\CoreBundle\Entity\User $manager
     *
     * @return AbstractWorkspace
     */
    public function createWorkspace(Configuration $config, User $manager)
    {
        $config->check();

        $workspaceType = $config->getWorkspaceType();
        $workspace = new $workspaceType;
        $workspace->setName($config->getWorkspaceName());
        $workspace->setPublic($config->isPublic());
        $workspace->setType($config->getType());
        $workspace->setCode($config->getWorkspaceCode());
        $this->entityManager->persist($workspace);
        $this->entityManager->flush();
        $this->initBaseRoles($workspace, $config);

        $rootDir = new Directory();
        $rootDir->setName("{$workspace->getName()} - {$workspace->getCode()}");
        $rootDir->setCreator($manager);
        $directoryType = $this->entityManager
            ->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceType')
            ->findOneBy(array('name' => 'directory'));
        $directoryIcon = $this->entityManager
            ->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceIcon')
            ->findOneBy(array('type' => 'directory', 'iconType' => 1));
        $rootDir->setIcon($directoryIcon);
        $rootDir->setResourceType($directoryType);
        $rootDir->setWorkspace($workspace);
        $this->setResourceOwnerRights(true, true, true, true, true, $rootDir);
        $this->entityManager->persist($rootDir);

        //default resource rights
        $this->createDefaultsResourcesRights(
            true, true, true, true, true, true,
            $this->roleRepo->getManagerRole($workspace),
            $rootDir
        );
        $this->createDefaultsResourcesRights(
            false, true, false, false, true, false,
            $this->roleRepo->getCollaboratorRole($workspace),
            $rootDir
        );
        $this->createDefaultsResourcesRights(
            false, false, false, false, false, false,
            $this->roleRepo->getVisitorRole($workspace),
            $rootDir
        );
        $this->createDefaultsResourcesRights(
            false, false, false, false, false, false,
            $this->roleRepo->findOneBy(array('name' => 'ROLE_ANONYMOUS')),
            $rootDir
        );

        //default workspace rights
        $this->createDefaultsWorkspaceRights(
            true, true, true,
            $this->roleRepo->getManagerRole($workspace), $workspace
        );
        $this->createDefaultsWorkspaceRights(
            true, false, false,
            $this->roleRepo->getCollaboratorRole($workspace), $workspace
        );
        $this->createDefaultsWorkspaceRights(
            true, false, false,
            $this->roleRepo->getVisitorRole($workspace), $workspace
        );
        $this->createDefaultsWorkspaceRights(
            false, false, false,
            $this->roleRepo->findOneBy(array('name' => 'ROLE_ANONYMOUS')),
            $workspace
        );

        $manager->addRole($this->roleRepo->getManagerRole($workspace));
        $this->entityManager->persist($manager);
        $this->entityManager->flush();

        return $workspace;
    }

    /**
     * Create default permissions for a role and a resource.
     *
     * @param boolean $canDelete
     * @param boolean $canOpen
     * @param boolean $canEdit
     * @param boolean $canCopy
     * @param boolean $canExport
     * @param boolean $canCreate
     * @param \Claroline\CoreBundle\Entity\Role $role
     * @param \Claroline\CoreBundle\Entity\Resource\AbstractResource $resource
     *
     * @return \Claroline\CoreBundle\Entity\Rights\ResourceRights
     */
    private function createDefaultsResourcesRights(
        $canDelete,
        $canOpen,
        $canEdit,
        $canCopy,
        $canExport,
        $canCreate,
        Role $role,
        AbstractResource $resource
    )
    {
        $resourceRight = new ResourceRights();

        $resourceRight->setCanCopy($canCopy);
        $resourceRight->setCanDelete($canDelete);
        $resourceRight->setCanEdit($canEdit);
        $resourceRight->setCanOpen($canOpen);
        $resourceRight->setCanExport($canExport);
        $resourceRight->setRole($role);
        $resourceRight->setResource($resource);

        if ($canCreate) {
            $resourceTypes = $this->entityManager
                ->getRepository('ClarolineCoreBundle:Resource\ResourceType')
                ->findBy(array('isVisible' => true));

            foreach ($resourceTypes as $resourceType) {
                $resourceRight->addResourceType($resourceType);
            }
        }

        $this->entityManager->persist($resourceRight);

        return $resourceRight;
    }

    /**
     * Create default permissions for a role and a workspace.
     *
     * @param boolean $canView
     * @param boolean $canEdit
     * @param boolean $canDelete
     * @param \Claroline\CoreBundle\Entity\Role $role
     * @param \Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace $workspace
     */
    private function createDefaultsWorkspaceRights(
        $canView,
        $canEdit,
        $canDelete,
        Role $role,
        AbstractWorkspace $workspace
    )
    {
        $workspaceRight = new WorkspaceRights();
        $workspaceRight->setCanView($canView);
        $workspaceRight->setCanEdit($canEdit);
        $workspaceRight->setCanDelete($canDelete);
        $workspaceRight->setRole($role);
        $workspaceRight->setWorkspace($workspace);

        $this->entityManager->persist($workspaceRight);
    }

    /**
     * Creates the base roles of a workspace.
     *
     * @param \Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace $workspace
     * @param \Claroline\CoreBundle\Library\Workspace\Configuration $config
     */
    private function initBaseRoles(AbstractWorkspace $workspace, Configuration $config)
    {
        $this->createRole('VISITOR', $workspace, $config->getVisitorTranslationKey());
        $this->createRole('COLLABORATOR', $workspace, $config->getCollaboratorTranslationKey());
        $this->createRole('MANAGER', $workspace, $config->getManagerTranslationKey());

        $this->entityManager->flush();
    }

    /**
     * Creates a new role.
     *
     * @param string $baseName
     * @param \Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace $workspace
     * @param string $translationKey
     *
     * @return \Claroline\CoreBundle\Entity\Role
     */
    private function createRole($baseName, AbstractWorkspace $workspace, $translationKey)
    {
        $baseRole = new Role();
        $baseRole->setName('ROLE_WS_' . $baseName . '_' . $workspace->getId());
        $baseRole->setParent(null);
        $baseRole->setRoleType(Role::WS_ROLE);
        $baseRole->setTranslationKey($translationKey);

        $this->entityManager->persist($baseRole);

        return $baseRole;
    }

    private function setResourceOwnerRights(
        $isSharable,
        $isEditable,
        $isDeletable,
        $isExportable,
        $isCopiable,
        AbstractResource $resource
    )
    {
        $resource->setSharable($isSharable);
        $resource->setEditable($isEditable);
        $resource->setDeletable($isDeletable);
        $resource->setExportable($isExportable);
        $resource->setCopiable($isCopiable);
        $resourceTypes = $this->entityManager
            ->getRepository('ClarolineCoreBundle:Resource\ResourceType')
            ->findBy(array('isVisible' => true));

        foreach ($resourceTypes as $resourceType) {
            $resource->addResourceTypeCreation($resourceType);
        }

        return $resource;
    }
}