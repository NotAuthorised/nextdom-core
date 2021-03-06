<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once NEXTDOM_ROOT . '/core/php/core.inc.php';

use NextDom\Managers\ScenarioElementManager;
use NextDom\Managers\ScenarioSubElementManager;
use NextDom\Managers\ScenarioExpressionManager;

class scenarioElement {
    /*     * *************************Attributs****************************** */

    private $id;
    private $name;
    private $type;
    private $options;
    private $order = 0;
    private $_subelement;

    public static function byId($_id) {
        return ScenarioElementManager::byId($_id);
    }

    public static function saveAjaxElement($element_ajax) {
        return ScenarioElementManager::saveAjaxElement($element_ajax);
    }

    public function save() {
        DB::save($this);
    }

    public function remove() {
        foreach ($this->getSubElement() as $subelement) {
            $subelement->remove();
        }
        DB::remove($this);
    }

    public function execute(&$_scenario = null) {
        if ($_scenario != null && !$_scenario->getDo()) {
            return;
        }
        if (!is_object($this->getSubElement($this->getType()))) {
            return;
        }
        if ($this->getType() == 'if') {
            if ($this->getSubElement('if')->getOptions('enable', 1) == 0) {
                return true;
            }
            $result = $this->getSubElement('if')->execute($_scenario);
            if (is_string($result) && strlen($result) > 1) {
                $_scenario->setLog(__('Expression non valide : ', __FILE__) . $result);
                return;
            }
            if ($result) {
                if ($this->getSubElement('if')->getOptions('allowRepeatCondition', 0) == 1) {
                    if ($this->getSubElement('if')->getOptions('previousState', -1) != 1) {
                        $this->getSubElement('if')->setOptions('previousState', 1);
                        $this->getSubElement('if')->save();
                    } else {
                        $_scenario->setLog(__('Non exécution des actions pour cause de répétition', __FILE__));
                        return;
                    }
                }
                return $this->getSubElement('then')->execute($_scenario);
            }
            if (!is_object($this->getSubElement('else'))) {
                return;
            }
            if ($this->getSubElement('if')->getOptions('allowRepeatCondition', 0) == 1) {
                if ($this->getSubElement('if')->getOptions('previousState', -1) != 0) {
                    $this->getSubElement('if')->setOptions('previousState', 0);
                    $this->getSubElement('if')->save();
                } else {
                    $_scenario->setLog(__('Non exécution des actions pour cause de répétition', __FILE__));
                    return;
                }
            }
            return $this->getSubElement('else')->execute($_scenario);

        } elseif ($this->getType() == 'action') {
            if ($this->getSubElement('action')->getOptions('enable', 1) == 0) {
                return true;
            }
            return $this->getSubElement('action')->execute($_scenario);
        } elseif ($this->getType() == 'code') {
            if ($this->getSubElement('code')->getOptions('enable', 1) == 0) {
                return true;
            }
            return $this->getSubElement('code')->execute($_scenario);
        } elseif ($this->getType() == 'for') {
            $for = $this->getSubElement('for');
            if ($for->getOptions('enable', 1) == 0) {
                return true;
            }
            $limits = $for->getExpression();
            $limits = intval(nextdom::evaluateExpression($limits[0]->getExpression()));
            if (!is_numeric($limits)) {
                $_scenario->setLog(__('[ERREUR] La condition pour une boucle doit être numérique : ', __FILE__) . $limits);
                throw new Exception(__('La condition pour une boucle doit être numérique : ', __FILE__) . $limits);
            }
            $return = false;
            for ($i = 1; $i <= $limits; $i++) {
                $return = $this->getSubElement('do')->execute($_scenario);
            }
            return $return;
        } elseif ($this->getType() == 'in') {
            $in = $this->getSubElement('in');
            if ($in->getOptions('enable', 1) == 0) {
                return true;
            }
            $time = ceil(str_replace('.', ',', nextdom::evaluateExpression($in->getExpression()[0]->getExpression(), $_scenario)));
            if (!is_numeric($time) || $time < 0) {
                $time = 0;
            }
            if ($time == 0) {
                $cmd = dirname(__FILE__) . '/../../core/php/jeeScenario.php ';
                $cmd .= ' scenario_id=' . $_scenario->getId();
                $cmd .= ' scenarioElement_id=' . $this->getId();
                $cmd .= ' tags=' . escapeshellarg(json_encode($_scenario->getTags()));
                $cmd .= ' >> ' . log::getPathToLog('scenario_element_execution') . ' 2>&1 &';
                $_scenario->setLog(__('Tâche : ', __FILE__) . $this->getId() . __(' lancement immédiat ', __FILE__));
                system::php($cmd);
            } else {
                $crons = cron::searchClassAndFunction('scenario', 'doIn', '"scenarioElement_id":' . $this->getId() . ',');
                if (is_array($crons)) {
                    foreach ($crons as $cron) {
                        if ($cron->getState() != 'run') {
                            $cron->remove();
                        }
                    }
                }
                $cron = new cron();
                $cron->setClass('scenario');
                $cron->setFunction('doIn');
                $cron->setOption(array('scenario_id' => intval($_scenario->getId()), 'scenarioElement_id' => intval($this->getId()), 'second' => date('s'), 'tags' => $_scenario->getTags()));
                $cron->setLastRun(date('Y-m-d H:i:s'));
                $cron->setOnce(1);
                $next = strtotime('+ ' . $time . ' min');
                $cron->setSchedule(cron::convertDateToCron($next));
                $cron->save();
                $_scenario->setLog(__('Tâche : ', __FILE__) . $this->getId() . __(' programmé à : ', __FILE__) . date('Y-m-d H:i:s', $next) . ' (+ ' . $time . ' min)');
            }
            return true;
        } elseif ($this->getType() == 'at') {
            $at = $this->getSubElement('at');
            if ($at->getOptions('enable', 1) == 0) {
                return true;
            }
            $next = nextdom::evaluateExpression($at->getExpression()[0]->getExpression(), $_scenario);
            if (!is_numeric($next) || $next < 0) {
                throw new Exception(__('Bloc type A : ', __FILE__) . $this->getId() . __(', heure programmée invalide : ', __FILE__) . $next);
            }
            if ($next < date('Gi', strtotime('+1 minute' . date('G:i')))) {
                $next = str_repeat('0', 4 - strlen($next)) . $next;
                $next = date('Y-m-d', strtotime('+1 day' . date('Y-m-d'))) . ' ' . substr($next, 0, 2) . ':' . substr($next, 2, 4);
            } else {
                $next = str_repeat('0', 4 - strlen($next)) . $next;
                $next = date('Y-m-d') . ' ' . substr($next, 0, 2) . ':' . substr($next, 2, 4);
            }
            $next = strtotime($next);
            if ($next < strtotime('now')) {
                throw new Exception(__('Bloc type A : ', __FILE__) . $this->getId() . __(', heure programmée invalide : ', __FILE__) . date('Y-m-d H:i:00', $next));
            }
            $crons = cron::searchClassAndFunction('scenario', 'doIn', '"scenarioElement_id":' . $this->getId() . ',');
            if (is_array($crons)) {
                foreach ($crons as $cron) {
                    if ($cron->getState() != 'run') {
                        $cron->remove();
                    }
                }
            }
            $cron = new cron();
            $cron->setClass('scenario');
            $cron->setFunction('doIn');
            $cron->setOption(array('scenario_id' => intval($_scenario->getId()), 'scenarioElement_id' => intval($this->getId()), 'second' => 0, 'tags' => $_scenario->getTags()));
            $cron->setLastRun(date('Y-m-d H:i:s', strtotime('now')));
            $cron->setOnce(1);
            $cron->setSchedule(cron::convertDateToCron($next));
            $cron->save();
            $_scenario->setLog(__('Tâche : ', __FILE__) . $this->getId() . __(' programmée à : ', __FILE__) . date('Y-m-d H:i:00', $next));
            return true;
        }
    }

    public function getSubElement($_type = '') {
        if ($_type != '') {
            if (isset($this->_subelement[$_type]) && is_object($this->_subelement[$_type])) {
                return $this->_subelement[$_type];
            }
            $this->_subelement[$_type] = ScenarioSubElementManager::byScenarioElementId($this->getId(), $_type);
            return $this->_subelement[$_type];
        } else {
            if (isset($this->_subelement[-1]) && is_array($this->_subelement[-1]) && count($this->_subelement[-1]) > 0) {
                return $this->_subelement[-1];
            }
            $this->_subelement[-1] = ScenarioSubElementManager::byScenarioElementId($this->getId(), $_type);
            return $this->_subelement[-1];
        }
    }

    public function getAjaxElement($_mode = 'ajax') {
        $return = utils::o2a($this);
        if ($_mode == 'array') {
            if (isset($return['id'])) {
                unset($return['id']);
            }
            if (isset($return['scenarioElement_id'])) {
                unset($return['scenarioElement_id']);
            }
            if (isset($return['log'])) {
                unset($return['log']);
            }
            if (isset($return['_expression'])) {
                unset($return['_expression']);
            }
        }
        $return['subElements'] = array();
        foreach ($this->getSubElement() as $subElement) {
            $subElement_ajax = utils::o2a($subElement);
            if ($_mode == 'array') {
                if (isset($subElement_ajax['id'])) {
                    unset($subElement_ajax['id']);
                }
                if (isset($subElement_ajax['scenarioElement_id'])) {
                    unset($subElement_ajax['scenarioElement_id']);
                }
                if (isset($subElement_ajax['log'])) {
                    unset($subElement_ajax['log']);
                }
                if (isset($subElement_ajax['_expression'])) {
                    unset($subElement_ajax['_expression']);
                }
            }
            $subElement_ajax['expressions'] = array();
            foreach ($subElement->getExpression() as $expression) {
                $expression_ajax = utils::o2a($expression);
                if ($_mode == 'array') {
                    if (isset($expression_ajax['id'])) {
                        unset($expression_ajax['id']);
                    }
                    if (isset($expression_ajax['scenarioSubElement_id'])) {
                        unset($expression_ajax['scenarioSubElement_id']);
                    }
                    if (isset($expression_ajax['log'])) {
                        unset($expression_ajax['log']);
                    }
                    if (isset($expression_ajax['_expression'])) {
                        unset($expression_ajax['_expression']);
                    }
                }
                if ($expression->getType() == 'element') {
                    $element = self::byId($expression->getExpression());
                    if (is_object($element)) {
                        $expression_ajax['element'] = $element->getAjaxElement($_mode);
                        if ($_mode == 'array') {
                            if (isset($expression_ajax['element']['id'])) {
                                unset($expression_ajax['element']['id']);
                            }
                            if (isset($expression_ajax['element']['scenarioElement_id'])) {
                                unset($expression_ajax['element']['scenarioElement_id']);
                            }
                            if (isset($expression_ajax['element']['log'])) {
                                unset($expression_ajax['element']['log']);
                            }
                            if (isset($expression_ajax['element']['_expression'])) {
                                unset($expression_ajax['element']['_expression']);
                            }
                        }
                    }
                }
                $expression_ajax = nextdom::toHumanReadable($expression_ajax);
                $subElement_ajax['expressions'][] = $expression_ajax;
            }
            $return['subElements'][] = $subElement_ajax;
        }
        return $return;
    }

    public function getAllId() {
        $return = array(
            'element' => array($this->getId()),
            'subelement' => array(),
            'expression' => array(),
        );
        foreach ($this->getSubElement() as $subelement) {
            $result = $subelement->getAllId();
            $return['element'] = array_merge($return['element'], $result['element']);
            $return['subelement'] = array_merge($return['subelement'], $result['subelement']);
            $return['expression'] = array_merge($return['expression'], $result['expression']);
        }
        return $return;
    }

    public function resetRepeatIfStatus() {
        foreach ($this->getSubElement() as $subElement) {
            if ($subElement->getType() == 'if') {
                $subElement->setOptions('previousState', -1);
                $subElement->save();
            }
            foreach ($subElement->getExpression() as $expression) {
                $expression->resetRepeatIfStatus();
            }
        }
    }

    public function export() {
        $return = '';
        foreach ($this->getSubElement() as $subElement) {
            $return .= "\n";
            switch ($subElement->getType()) {
                case 'if':
                    $return .= __('SI', __FILE__);
                    break;
                case 'then':
                    $return .= __('ALORS', __FILE__);
                    break;
                case 'else':
                    $return .= __('SINON', __FILE__);
                    break;
                case 'for':
                    $return .= __('POUR', __FILE__);
                    break;
                case 'do':
                    $return .= __('FAIRE', __FILE__);
                    break;
                case 'code':
                    $return .= __('CODE', __FILE__);
                    break;
                case 'action':
                    $return .= __('ACTION', __FILE__);
                    break;
                case 'in':
                    $return .= __('DANS', __FILE__);
                    break;
                case 'at':
                    $return .= __('A', __FILE__);
                    break;
                default:
                    $return .= $subElement->getType();
                    break;
            }

            foreach ($subElement->getExpression() as $expression) {
                $export = $expression->export();
                if ($expression->getType() != 'condition' && trim($export) != '') {
                    $return .= "\n";
                }
                if (trim($export) != '') {
                    $return .= ' ' . $expression->export();
                }
            }
        }
        return $return;
    }

    public function copy() {
        $elementCopy = clone $this;
        $elementCopy->setId('');
        $elementCopy->save();
        foreach ($this->getSubElement() as $subelement) {
            $subelement->copy($elementCopy->getId());
        }
        return $elementCopy->getId();
    }

    public function getScenario() {
        $scenario = scenario::byElement($this->getId());
        if (is_object($scenario)) {
            return $scenario;
        }
        $expression = ScenarioExpressionManager::byElement($this->getId());
        if (is_object($expression)) {
            return $expression->getSubElement()->getElement()->getScenario();
        }
        return null;
    }

/*     * **********************Getteur Setteur*************************** */

    public function getId() {
        return $this->id;
    }

    public function setId($id) {
        $this->id = $id;
        return $this;
    }

    public function getName() {
        return $this->name;
    }

    public function setName($name) {
        $this->name = $name;
        return $this;
    }

    public function getType() {
        return $this->type;
    }

    public function setType($type) {
        $this->type = $type;
        return $this;
    }

    public function getOptions($_key = '', $_default = '') {
        return utils::getJsonAttr($this->options, $_key, $_default);
    }

    public function setOptions($_key, $_value) {
        $this->options = utils::setJsonAttr($this->options, $_key, $_value);
        return $this;
    }

    public function getOrder() {
        return $this->order;
    }

    public function setOrder($order) {
        $this->order = $order;
        return $this;
    }

}
