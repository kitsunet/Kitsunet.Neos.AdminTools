<?php
namespace Kitsunet\Neos\AdminTools\Controller;

/*                                                                           *
 * This script belongs to the TYPO3 Flow package "Kitsunet.Neos.AdminTools". *
 *                                                                           *
 *                                                                           */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeType;
use TYPO3\TYPO3CR\Domain\Model\Workspace;

/**
 * Class StandardController
 *
 * @package Kitsunet\Neos\AdminTools
 */
class StandardController extends \TYPO3\Flow\Mvc\Controller\ActionController {

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Cache\Frontend\StringFrontend
	 */
	protected $contentCache;
	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface
	 */
	protected $contextFactory;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
	 */
	protected $nodeDataRepository;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
	 */
	protected $nodeTypeManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository
	 */
	protected $workspaceRepository;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Factory\NodeFactory
	 */
	protected $nodeFactory;

	/**
	 * @return void
	 */
	public function indexAction() {
		$this->view->assign('workspaces', $this->workspaceRepository->findAll());
	}

	/**
	 * @return void
	 */
	public function clearContentCacheAction() {
		$this->contentCache->flush();
		$this->addFlashMessage('The Neos content cache was flushed.');
		$this->redirect('index');
	}

	/**
	 * @param Workspace $workspace
	 * @return void
	 */
	public function createChildNodesAction(Workspace $workspace = NULL) {
		if ($workspace === NULL) {
			$workspaces = $this->workspaceRepository->findAll()->toArray();
		} else {
			$workspaces = array($workspace);
		}

		foreach ($workspaces as $workspace) {
			$output = $this->createChildNodesForWorkspace($workspace);
			$this->addFlashMessage(implode("\n", $output), 'Auto created child nodes for ' . $workspace->getName());
		}

		$this->redirect('index');
	}

	/**
	 * @param Workspace $workspace
	 * @return array
	 */
	protected function createChildNodesForWorkspace(Workspace $workspace) {
		$output = array();
		foreach ($this->nodeTypeManager->getNodeTypes() as $nodeType) {
			/** @var NodeType $nodeType */
			if ($nodeType->isAbstract()) {
				continue;
			}
			$output = array_merge($output, $this->createChildNodesByNodeType($nodeType, $workspace->getName(), FALSE));
		}

		return $output;
	}

	/**
	 * Create missing child nodes for the given node type
	 *
	 * @param NodeType $nodeType
	 * @param string $workspace
	 * @param boolean $dryRun
	 * @return array
	 */
	protected function createChildNodesByNodeType(NodeType $nodeType, $workspace, $dryRun) {
		$output = array();
		$createdNodesCount = 0;
		$nodeCreationExceptions = 0;

		$nodeTypes = $this->nodeTypeManager->getSubNodeTypes($nodeType->getName(), FALSE);
		$nodeTypes[$nodeType->getName()] = $nodeType;

		if ($this->nodeTypeManager->hasNodeType((string)$nodeType)) {
			$nodeType = $this->nodeTypeManager->getNodeType((string)$nodeType);
			$nodeTypeNames[$nodeType->getName()] = $nodeType;
		} else {
			return array($this->outputLine('Node type "%s" does not exist', array((string)$nodeType)));
		}

		$output[] = '';
		$output[] = $this->outputLine('Working on node type "%s" ...', array((string)$nodeType));

		/** @var $nodeType NodeType */
		foreach ($nodeTypes as $nodeTypeName => $nodeType) {
			$childNodes = $nodeType->getAutoCreatedChildNodes();
			$context = $this->createContext($workspace);
			foreach ($this->nodeDataRepository->findByNodeType($nodeTypeName) as $nodeData) {
				$node = $this->nodeFactory->createFromNodeData($nodeData, $context);
				if (!$node instanceof NodeInterface || $node->isRemoved() === TRUE) {
					continue;
				}
				foreach ($childNodes as $childNodeName => $childNodeType) {
					try {
						$childNodeMissing = $node->getNode($childNodeName) ? FALSE : TRUE;
						if ($childNodeMissing) {
							if ($dryRun === FALSE) {
								$node->createNode($childNodeName, $childNodeType);
								$output[] = $this->outputLine('Auto created node named "%s" in "%s"', array($childNodeName, $node->getPath()));
							} else {
								$output[] = $this->outputLine('Missing node named "%s" in "%s"', array($childNodeName, $node->getPath()));
							}
							$createdNodesCount++;
						}
					} catch (\Exception $exception) {
						$output[] = $this->outputLine('Could not create node named "%s" in "%s" (%s)', array($childNodeName, $node->getPath(), $exception->getMessage()));
						$nodeCreationExceptions++;
					}
				}
			}
		};

		if ($createdNodesCount === 0 && $nodeCreationExceptions === 0) {
			$output[] = $this->outputLine('All "%s" nodes in workspace "%s" have an up-to-date structure', array((string)$nodeType, $workspace));
		} else {
			if ($dryRun === FALSE) {
				$output[] = $this->outputLine('Created %s new child nodes', array($createdNodesCount));

				if ($nodeCreationExceptions > 0) {
					$output[] = $this->outputLine('%s Errors occurred during child node creation', array($nodeCreationExceptions));
				}
			} else {
				$output[] = $this->outputLine('%s missing child nodes need to be created', array($createdNodesCount));
			}
		}
		$output[] = '';

		return $output;
	}

	/**
	 * Creates a content context for given workspace
	 *
	 * @param string $workspaceName
	 * @return \TYPO3\TYPO3CR\Domain\Service\Context
	 */
	protected function createContext($workspaceName) {
		return $this->contextFactory->create(array(
			'workspaceName' => $workspaceName,
			'invisibleContentShown' => TRUE,
			'inaccessibleContentShown' => TRUE
		));
	}

	/**
	 * Just a wrapper around vsprintf
	 *
	 * Quick hack to not touch too much of the code.
	 *
	 * @param string $line
	 * @param array $arguments
	 * @return string
	 */
	protected function outputLine($line, $arguments) {
		return vsprintf($line, $arguments);
	}

}

?>