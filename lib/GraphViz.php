<?php

class GraphViz{
    /**
     * original graph
     * 
     * @var Graph
     * @see GraphViz::getGraph()
     */
    private $graph;
    
    private $layoutGraph = array();
    private $layoutVertex = array();
    private $layoutEdge = array();
    private $layoutObject;
    
    /**
     * file output format to use
     * 
     * @var string
     * @see GraphViz::setFormat()
     */
    private $format = 'png';
    
    /**
     * end-of-line to be appended to each line
     * 
     * @var string
     */
    const EOL = PHP_EOL;
    
    const LAYOUT_GRAPH = 1;
    const LAYOUT_EDGE = 2;
    const LAYOUT_VERTEX = 3;
    
	public function __construct(Graph $graphToPlot){
		$this->graph = $graphToPlot;
		$this->layoutObject = new SplObjectStorage();
	}
	
	/**
	 * get original graph (with no layout and styles)
	 *
	 * @return Graph
	 */
	public function getGraph(){
	    return $this->graph;
	}
	
	/**
	 * set graph image output format
	 * 
	 * @param string $format png, svg, ps2, etc. (see 'man dot' for details on parameter '-T') 
	 * @return GraphViz $this (chainable)
	 */
	public function setFormat($format){
	    $this->format = $format;
	    return $this;
	}
    
	/**
	 * set attribute/layout/style for given element(s)
	 * 
	 * @param Vertex|Edge|array|int $where
	 * @param string|array          $layout
	 * @param mixed                 $value
	 * @return GraphViz $this (chainable)
	 * @throws Exception
	 */
	public function setAttribute($where,$layout,$value=NULL){
	    if(!is_array($where)){
	        $where = array($where);
	    }
	    if(func_num_args() > 2){
	        $layout = array($layout=>$value);
	    }
	    foreach($where as $where){
	        if($where === self::LAYOUT_GRAPH){
	            $this->mergeLayout($this->layoutGraph,$layout);
	        }else if($where === self::LAYOUT_EDGE){
	            $this->mergeLayout($this->layoutEdge,$layout);
	        }else if($where === self::LAYOUT_VERTEX){
	            $this->mergeLayout($this->layoutVertex,$layout);
	        }else if($where instanceof Edge || $where instanceof Vertex){
	            $temp = isset($this->layoutObject[$where]) ? $this->layoutObject[$where] : array();
	            $this->mergeLayout($temp,$layout);
	            if($temp){
	                $this->layoutObject[$where] = $temp;
	            }else{
	                unset($this->layoutObject[$where]);
	            }
	        }else{
	            throw new Exception('Invalid layout identifier');
	        }
	    }
	    return $this;
	    
	    // example code:
	
	    // set global graph layout
	    $this->setLayout(self::LAYOUT_GRAPH,'bgcolor','transparent');
	
	    // assign multiple layout settings to all vertices
	    $this->setLayout(self::LAYOUT_VERTEX,array('size'=>8,'color'=>'blue'));
	
	    // assign layout to single edge
	    $this->setLayout($graph->getVertexFirst(),'shape','square');
	
	    // assign multiple layout settings to multiple edges
	    $this->setLayout($alg->getEdges(),array('color'=>'red','style'=>'bold'));
	
	    // ?? assign layout to vertexm, then delete vertex
	    $vertex = $graph->createVertex();
	    $this->setLayout($vertex,'color','red');
	    $vertex->destroy();
	    $this->display();
	}
	
	/**
	 * create and display image for this graph
	 * 
	 * @return void
	 * @uses GraphViz::createImageFile()
	 */
	public function display(){
        //echo "Generate picture ...";
        $tmp = $this->createImageFile();
        
        static $next = 0;
        if($next > microtime(true)){
            echo '[delay flooding xdg-open]'.PHP_EOL; // wait some time between calling xdg-open because earlier calls will be ignored otherwise
            sleep(1);
        }
        exec('xdg-open '.escapeshellarg($tmp).' > /dev/null 2>&1 &'); // open image in background (redirect stdout to /dev/null, sterr to stdout and run in background)
        $next = microtime(true) + 1.0;
        //echo "... done\n";
	}
	
	/**
	 * create base64-encoded image src target data to be used for html images
	 * 
	 * @return string
	 * @uses GraphViz::createImageData()
	 */
	public function createImageSrc(){
	    $format = ($this->format === 'svg' || $this->format === 'svgz') ? 'svg+xml' : $this->format;
	    return 'data:image/'.$format.';base64,'.base64_encode($this->createImageData());
	}
	
	/**
	 * create image html code for this graph
	 * 
	 * @return string
	 * @uses GraphViz::createImageSrc()
	 */
	public function createImageHtml(){
	    if($this->format === 'svg' || $this->format === 'svgz'){
	        return '<object type="image/svg+xml" data="'.$this->createImageSrc().'"></object>';
	    }
	    return '<img src="'.$this->createImageSrc().'" />';
	}
	
	/**
	 * create image file data contents for this graph
	 * 
	 * @return string
	 * @uses GraphViz::createImageFile()
	 */
	public function createImageData(){
	    $file = $this->createImageFile();
	    $data = file_get_contents($file);
	    unlink($file);
	    return $data;
	}
	
	/**
	 * create image file for this graph
	 * 
	 * @return string filename
	 * @throws Exception on error
	 * @uses GraphViz::createScript()
	 */
	public function createImageFile(){
        $script = $this->createScript();
	    //var_dump($script);
	    
	    $tmp = tempnam('/tmp','graphviz');
	    if($tmp === false){
	        throw new Exception('Unable to get temporary file name for graphviz script');
	    }
	    
	    $ret = file_put_contents($tmp,$script,LOCK_EX);
	    if($ret === false){
	        throw new Exception('Unable to write graphviz script to temporary file "'.$tmp.'"');
	    }
	    
	    $ret = 0;
	    system('dot -T '.escapeshellarg($this->format).' '.escapeshellarg($tmp).' -o '.escapeshellarg($tmp.'.'.$this->format),$ret); // use program 'dot' to actually generate graph image
	    if($ret !== 0){
	        throw new Exception('Unable to invoke "dot" to create image file (code '.$ret.')');
	    }
	    
	    unlink($tmp);
	    
	    return $tmp.'.'.$this->format;
	}
	
	/**
	 * create graphviz script representing this graph
	 * 
	 * @return string
	 * @uses Graph::isDirected()
	 * @uses Graph::getVertices()
	 * @uses Graph::getEdges()
	 */
	public function createScript(){
	    $directed = $this->graph->isDirected();
	    
		$script = ($directed ? 'di':'') . 'graph G {'.self::EOL;
		
		// add global attributes
		if($this->layoutGraph){
			$script .= '  graph ' . $this->escapeAttributes($this->layoutGraph) . self::EOL;
		}
		if($this->layoutVertex){
			$script .= '  node ' . $this->escapeAttributes($this->layoutVertex) . self::EOL;
		}
		if($this->layoutEdge){
			$script .= '  edge ' . $this->escapeAttributes($this->layoutEdge) . self::EOL;
		}
		
		// explicitly add all isolated vertices (vertices with no edges) and vertices with special layout set
		// other vertices wil be added automatically due to below edge definitions
		foreach ($this->graph->getVertices() as $vertex){
		    if($vertex->isIsolated() || isset($this->layoutObject[$vertex])){
		        $script .= '  ' . $this->escapeId($vertex->getId());
				if(isset($this->layoutObject[$vertex])){
					$script .= ' ' . $this->escapeAttributes($this->layoutObject[$vertex]);
				}
				$script .= self::EOL;
		    }
		}
		
		$edgeop = $directed ? ' -> ' : ' -- ';
		
		// add all edges as directed edges
		foreach ($this->graph->getEdges() as $currentEdge){
		    $both = $currentEdge->getVertices();
		    $currentStartVertex = $both[0];
		    $currentTargetVertex = $both[1];
		    
		    $script .= '  ' . $this->escapeId($currentStartVertex->getId()) . $edgeop . $this->escapeId($currentTargetVertex->getId());
	        
		    $attrs = isset($this->layoutObject[$currentEdge]) ? $this->layoutObject[$currentEdge] : array();
		    
    	    $weight = $currentEdge->getWeight();
    	    if($weight !== NULL){                                       // add weight as label (if set)
    	        $attrs['label']  = $weight;
     	        //$attrs['weight'] = $weight;
    	    }
    	    // this edge also points to the opposite direction => this is actually an undirected edge
    	    if($directed && $currentEdge->isConnection($currentTargetVertex,$currentStartVertex)){
    	        $attrs['dir'] = 'none';
    	    }
    	    if($attrs){
    	        $script .= ' '.$this->escapeAttributes($attrs);
    	    }
    	    
    	    $script .= self::EOL;
		}
		$script .= '}'.self::EOL;
	    return $script;
	}
	
	/**
	 * escape given id string and wrap in quotes if needed
	 * 
	 * @param string $id
	 * @return string
	 * @link http://graphviz.org/content/dot-language
	 */
	private function escapeId($id){
	    // see @link: There is no semantic difference between abc_2 and "abc_2"
	    if(preg_match('/^(?:\-?(?:\.\d+|\d+(?:\.\d+)?)|[a-z_][a-z0-9_]*)$/i',$id)){ // numeric or simple string, no need to quote (only for simplicity)
	        return $id;
	    }
	    return '"'.str_replace(array('&','<','>','"',"'",'\\'),array('&amp;','&lt;','&gt;','&quot;','&apos;','\\\\'),$id).'"';
	}
	
	/**
	 * get escaped attribute string for given array of (unescaped) attributes
	 * 
	 * @param array $attrs
	 * @return string
	 * @uses GraphViz::escapeId()
	 */
	private function escapeAttributes($attrs){
        $script = '[';
        $first = true;
        foreach($attrs as $name=>$value){
            if($first){
                $first = false;
            }else{
                $script .= ' ';
            }
            $script .= $name.'='.$this->escapeId($value);
        }
        $script .= ']';
	    return $script;
	}
	
	private function mergeLayout(&$old,$new){
	    if($new === NULL){
	        $old = array();
	    }else{
	        foreach($new as $key=>$value){
	            if($value === NULL){
	                unset($old[$key]);
	            }else{
	                $old[$key] = $value;
	            }
	        }
	    }
	}
}
