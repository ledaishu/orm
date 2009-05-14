<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Internal\Hydration;

use \PDO;

/**
 * Description of ArrayHydrator
 *
 * @author robo
 */
class ArrayHydrator extends AbstractHydrator
{
    private $_rootAliases = array();
    private $_isSimpleQuery = false;
    private $_identifierMap = array();
    private $_resultPointers = array();
    private $_idTemplate = array();
    private $_resultCounter = 0;

    /** @override */
    protected function _prepare()
    {
        $this->_isSimpleQuery = $this->_resultSetMapping->getEntityResultCount() <= 1;
        $this->_identifierMap = array();
        $this->_resultPointers = array();
        $this->_idTemplate = array();
        $this->_resultCounter = 0;
        foreach ($this->_resultSetMapping->getAliasMap() as $dqlAlias => $class) {
            $this->_identifierMap[$dqlAlias] = array();
            $this->_resultPointers[$dqlAlias] = array();
            $this->_idTemplate[$dqlAlias] = '';
        }
    }

    /** @override */
    protected function _hydrateAll()
    {
        $s = microtime(true);

        $result = array();
        $cache = array();
        while ($data = $this->_stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->_hydrateRow($data, $cache, $result);
        }

        $e = microtime(true);
        echo 'Hydration took: ' . ($e - $s) . PHP_EOL;

        return $result;
    }

    /** @override */
    protected function _hydrateRow(array &$data, array &$cache, &$result)
    {
        // 1) Initialize
        $id = $this->_idTemplate; // initialize the id-memory
        $nonemptyComponents = array();
        $rowData = $this->_gatherRowData($data, $cache, $id, $nonemptyComponents);

        // Extract scalar values. They're appended at the end.
        if (isset($rowData['scalars'])) {
            $scalars = $rowData['scalars'];
            unset($rowData['scalars']);
        }

        // 3) Now hydrate the rest of the data found in the current row, that
        // belongs to other (related) entities.
        foreach ($rowData as $dqlAlias => $data) {
            $index = false;

            if (isset($this->_resultSetMapping->parentAliasMap[$dqlAlias])) {
                // It's a joined result

                $parent = $this->_resultSetMapping->parentAliasMap[$dqlAlias];
                $relation = $this->_resultSetMapping->relationMap[$dqlAlias];
                $relationAlias = $relation->getSourceFieldName();
                $path = $parent . '.' . $dqlAlias;

                // Get a reference to the right element in the result tree.
                // This element will get the associated element attached.
                if ($this->_resultSetMapping->isMixed && isset($this->_rootAliases[$parent])) {
                    $key = key(reset($this->_resultPointers));
                    // TODO: Exception if $key === null ?
                    $baseElement =& $this->_resultPointers[$parent][$key];
                } else if (isset($this->_resultPointers[$parent])) {
                    $baseElement =& $this->_resultPointers[$parent];
                } else {
                    unset($this->_resultPointers[$dqlAlias]); // Ticket #1228
                    continue;
                }

                // Check the type of the relation (many or single-valued)
                if ( ! $relation->isOneToOne()) {
                    $oneToOne = false;
                    if (isset($nonemptyComponents[$dqlAlias])) {
                        if ( ! isset($baseElement[$relationAlias])) {
                            $baseElement[$relationAlias] = array();
                        }
                        $indexExists = isset($this->_identifierMap[$path][$id[$parent]][$id[$dqlAlias]]);
                        $index = $indexExists ? $this->_identifierMap[$path][$id[$parent]][$id[$dqlAlias]] : false;
                        $indexIsValid = $index !== false ? isset($baseElement[$relationAlias][$index]) : false;
                        if ( ! $indexExists || ! $indexIsValid) {
                            $element = $data;
                            if ($field = $this->_getCustomIndexField($dqlAlias)) {
                                $baseElement[$relationAlias][$element[$field]] = $element;
                            } else {
                                $baseElement[$relationAlias][] = $element;
                            }
                            end($baseElement[$relationAlias]);
                            $this->_identifierMap[$path][$id[$parent]][$id[$dqlAlias]] =
                            key($baseElement[$relationAlias]);
                        }
                    } else if ( ! isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = array();
                    }
                } else {
                    $oneToOne = true;
                    if ( ! isset($nonemptyComponents[$dqlAlias]) && ! isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = null;
                    } else if ( ! isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = $data;
                    }
                }

                $coll =& $baseElement[$relationAlias];

                if ($coll !== null) {
                    $this->updateResultPointer($coll, $index, $dqlAlias, $oneToOne);
                }

            } else {
                $this->_rootAliases[$dqlAlias] = true; // Mark as root
                // 2) Hydrate the data of the root entity from the current row
                // Check for an existing element
                if ($this->_isSimpleQuery || ! isset($this->_identifierMap[$dqlAlias][$id[$dqlAlias]])) {
                    $element = $rowData[$dqlAlias];
                    if ($field = $this->_getCustomIndexField($dqlAlias)) {
                        if ($this->_resultSetMapping->isMixed) {
                            $result[] = array($element[$field] => $element);
                            ++$this->_resultCounter;
                        } else {
                            $result[$element[$field]] = $element;
                        }
                    } else {
                        if ($this->_resultSetMapping->isMixed) {
                            $result[] = array($element);
                            ++$this->_resultCounter;
                        } else {
                            $result[] = $element;
                        }
                    }
                    end($result);
                    $this->_identifierMap[$dqlAlias][$id[$dqlAlias]] = key($result);
                } else {
                    $index = $this->_identifierMap[$dqlAlias][$id[$dqlAlias]];
                }
                $this->updateResultPointer($result, $index, $dqlAlias, false);
                //unset($rowData[$rootAlias]);
            }
        }

        // Append scalar values to mixed result sets
        if (isset($scalars)) {
            foreach ($scalars as $name => $value) {
                $result[$this->_resultCounter - 1][$name] = $value;
            }
        }
    }

    /**
     * Updates the result pointer for an Entity. The result pointers point to the
     * last seen instance of each Entity type. This is used for graph construction.
     *
     * @param array $coll  The element.
     * @param boolean|integer $index  Index of the element in the collection.
     * @param string $dqlAlias
     * @param boolean $oneToOne  Whether it is a single-valued association or not.
     */
    private function updateResultPointer(&$coll, $index, $dqlAlias, $oneToOne)
    {
        if ($coll === null) {
            unset($this->_resultPointers[$dqlAlias]); // Ticket #1228
            return;
        }
        if ($index !== false) {
            $this->_resultPointers[$dqlAlias] =& $coll[$index];
            return;
        } else {
            if ($coll) {
                if ($oneToOne) {
                    $this->_resultPointers[$dqlAlias] =& $coll;
                } else {
                    end($coll);
                    $this->_resultPointers[$dqlAlias] =& $coll[key($coll)];
                }
            }
        }
    }

    /** {@inheritdoc} */
    protected function _getRowContainer()
    {
        return array();
    }
}