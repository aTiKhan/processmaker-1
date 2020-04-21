<?php

namespace ProcessMaker\Traits;

use ProcessMaker\Models\ProcessVersion;
use ProcessMaker\Nayra\Contracts\Storage\BpmnDocumentInterface;
use ProcessMaker\Nayra\Exceptions\ElementNotFoundException;
use ProcessMaker\Repositories\BpmnDocument;

trait ProcessTrait
{
    /**
     * Parsed process BPMN definitions.
     *
     * @var \ProcessMaker\Nayra\Contracts\Storage\BpmnDocumentInterface
     */
    private $bpmnDefinitions;

    /**
     * Get the process definitions from BPMN field.
     *
     * @param bool $forceParse
     *
     * @return BpmnDocument
     */
    public function getDefinitions($forceParse = false, $engine = null, $globalEvents = true)
    {
        if ($forceParse || empty($this->bpmnDefinitions)) {
            $options = ['process' => $this instanceof ProcessVersion ? $this->process : $this];
            !$engine ?: $options['engine'] = $engine;
            $options['globalEvents'] = $globalEvents;
            $this->bpmnDefinitions = app(BpmnDocumentInterface::class, $options);
            if ($this->bpmn) {
                $this->bpmnDefinitions->loadXML($this->bpmn);
                try {
                    $this->bpmnDefinitions->getEngine()->loadProcessDefinitions($this->bpmnDefinitions);
                } catch (ElementNotFoundException $e) {
                    $warnings = is_array($this->warnings) ? $this->warnings : [];
                    $warnings[] = [
                        'title' => __('Element Not Found'),
                        'text' => $e->getMessage()
                    ];
                    $this->warnings = $warnings;
                }
            }
        }
        return $this->bpmnDefinitions;
    }

    /**
     * Get BPMN DOM Document
     *
     * @return BpmnDocument
     */
    public function getDomDocument()
    {
        $document = new BpmnDocument($this);
        $document->loadXML($this->bpmn);
        return $document;
    }
}
