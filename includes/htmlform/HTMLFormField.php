<?php

/**
 * The parent class to generate form fields.  Any field type should
 * be a subclass of this.
 *
 * @stable to extend
 */
abstract class HTMLFormField {
	/** @var array|array[] */
	public $mParams;

	protected $mValidationCallback;
	protected $mFilterCallback;
	protected $mName;
	protected $mDir;
	protected $mLabel; # String label, as HTML. Set on construction.
	protected $mID;
	protected $mClass = '';
	protected $mVFormClass = '';
	protected $mHelpClass = false;
	protected $mDefault;
	/**
	 * @var array|bool|null
	 */
	protected $mOptions = false;
	protected $mOptionsLabelsNotFromMessage = false;
	/**
	 * @var array Array to hold params for 'hide-if' or 'disable-if' statements
	 */
	protected $mCondState = [ 'class' => [] ];

	/**
	 * @var bool If true will generate an empty div element with no label
	 * @since 1.22
	 */
	protected $mShowEmptyLabels = true;

	/**
	 * @var HTMLForm|null
	 */
	public $mParent;

	/**
	 * This function must be implemented to return the HTML to generate
	 * the input object itself.  It should not implement the surrounding
	 * table cells/rows, or labels/help messages.
	 *
	 * @param mixed $value The value to set the input to; eg a default
	 *     text for a text input.
	 *
	 * @return string Valid HTML.
	 */
	abstract public function getInputHTML( $value );

	/**
	 * Same as getInputHTML, but returns an OOUI object.
	 * Defaults to false, which getOOUI will interpret as "use the HTML version"
	 * @stable to override
	 *
	 * @param string $value
	 * @return OOUI\Widget|false
	 */
	public function getInputOOUI( $value ) {
		return false;
	}

	/**
	 * True if this field type is able to display errors; false if validation errors need to be
	 * displayed in the main HTMLForm error area.
	 * @stable to override
	 * @return bool
	 */
	public function canDisplayErrors() {
		return $this->hasVisibleOutput();
	}

	/**
	 * Get a translated interface message
	 *
	 * This is a wrapper around $this->mParent->msg() if $this->mParent is set
	 * and wfMessage() otherwise.
	 *
	 * Parameters are the same as wfMessage().
	 *
	 * @param string|string[]|MessageSpecifier $key
	 * @param mixed ...$params
	 * @return Message
	 */
	public function msg( $key, ...$params ) {
		if ( $this->mParent ) {
			return $this->mParent->msg( $key, ...$params );
		}
		return wfMessage( $key, ...$params );
	}

	/**
	 * If this field has a user-visible output or not. If not,
	 * it will not be rendered
	 * @stable to override
	 *
	 * @return bool
	 */
	public function hasVisibleOutput() {
		return true;
	}

	/**
	 * Fetch a field value from $alldata for the closest field matching a given
	 * name.
	 *
	 * This is complex because it needs to handle array fields like the user
	 * would expect. The general algorithm is to look for $name as a sibling
	 * of $this, then a sibling of $this's parent, and so on. Keeping in mind
	 * that $name itself might be referencing an array.
	 *
	 * @param array $alldata
	 * @param string $name
	 * @return string
	 */
	protected function getNearestFieldByName( $alldata, $name ) {
		$tmp = $this->mName;
		$thisKeys = [];
		while ( preg_match( '/^(.+)\[([^\]]+)\]$/', $tmp, $m ) ) {
			array_unshift( $thisKeys, $m[2] );
			$tmp = $m[1];
		}
		if ( substr( $tmp, 0, 2 ) == 'wp' &&
			!array_key_exists( $tmp, $alldata ) &&
			array_key_exists( substr( $tmp, 2 ), $alldata )
		) {
			// Adjust for name mangling.
			$tmp = substr( $tmp, 2 );
		}
		array_unshift( $thisKeys, $tmp );

		$tmp = $name;
		$nameKeys = [];
		while ( preg_match( '/^(.+)\[([^\]]+)\]$/', $tmp, $m ) ) {
			array_unshift( $nameKeys, $m[2] );
			$tmp = $m[1];
		}
		array_unshift( $nameKeys, $tmp );

		$testValue = '';
		for ( $i = count( $thisKeys ) - 1; $i >= 0; $i-- ) {
			$keys = array_merge( array_slice( $thisKeys, 0, $i ), $nameKeys );
			$data = $alldata;
			foreach ( $keys as $key ) {
				if ( !is_array( $data ) || !array_key_exists( $key, $data ) ) {
					continue 2;
				}
				$data = $data[$key];
			}
			$testValue = (string)$data;
			break;
		}

		return $testValue;
	}

	/**
	 * Helper function for isHidden to handle recursive data structures.
	 *
	 * @param array $alldata
	 * @param array $params
	 * @return bool
	 * @throws MWException
	 */
	protected function isHiddenRecurse( array $alldata, array $params ) {
		$origParams = $params;
		$op = array_shift( $params );

		try {
			switch ( $op ) {
				case 'AND':
					foreach ( $params as $i => $p ) {
						if ( !is_array( $p ) ) {
							throw new MWException(
								"Expected array, found " . gettype( $p ) . " at index $i"
							);
						}
						if ( !$this->isHiddenRecurse( $alldata, $p ) ) {
							return false;
						}
					}
					return true;

				case 'OR':
					foreach ( $params as $i => $p ) {
						if ( !is_array( $p ) ) {
							throw new MWException(
								"Expected array, found " . gettype( $p ) . " at index $i"
							);
						}
						if ( $this->isHiddenRecurse( $alldata, $p ) ) {
							return true;
						}
					}
					return false;

				case 'NAND':
					foreach ( $params as $i => $p ) {
						if ( !is_array( $p ) ) {
							throw new MWException(
								"Expected array, found " . gettype( $p ) . " at index $i"
							);
						}
						if ( !$this->isHiddenRecurse( $alldata, $p ) ) {
							return true;
						}
					}
					return false;

				case 'NOR':
					foreach ( $params as $i => $p ) {
						if ( !is_array( $p ) ) {
							throw new MWException(
								"Expected array, found " . gettype( $p ) . " at index $i"
							);
						}
						if ( $this->isHiddenRecurse( $alldata, $p ) ) {
							return false;
						}
					}
					return true;

				case 'NOT':
					if ( count( $params ) !== 1 ) {
						throw new MWException( "NOT takes exactly one parameter" );
					}
					$p = $params[0];
					if ( !is_array( $p ) ) {
						throw new MWException(
							"Expected array, found " . gettype( $p ) . " at index 0"
						);
					}
					return !$this->isHiddenRecurse( $alldata, $p );

				case '===':
				case '!==':
					if ( count( $params ) !== 2 ) {
						throw new MWException( "$op takes exactly two parameters" );
					}
					list( $field, $value ) = $params;
					if ( !is_string( $field ) || !is_string( $value ) ) {
						throw new MWException( "Parameters for $op must be strings" );
					}
					$testValue = $this->getNearestFieldByName( $alldata, $field );
					switch ( $op ) {
						case '===':
							return ( $value === $testValue );
						case '!==':
							return ( $value !== $testValue );
					}
					// fall-through, if $op is not added to both cases
				default:
					throw new MWException( "Unknown operation" );
			}
		} catch ( Exception $ex ) {
			throw new MWException(
				"Invalid hide-if specification for $this->mName: " .
				$ex->getMessage() . " in " . var_export( $origParams, true ),
				0, $ex
			);
		}
	}

	/**
	 * Test whether this field is supposed to be hidden, based on the values of
	 * the other form fields.
	 *
	 * @since 1.23
	 * @param array $alldata The data collected from the form
	 * @return bool
	 */
	public function isHidden( $alldata ) {
		if ( $this->mCondState['class'] || !isset( $this->mCondState['hide'] ) ) {
			return false;
		}

		return $this->isHiddenRecurse( $alldata, $this->mCondState['hide'] );
	}

	/**
	 * Override this function if the control can somehow trigger a form
	 * submission that shouldn't actually submit the HTMLForm.
	 *
	 * @stable to override
	 * @since 1.23
	 * @param string|array $value The value the field was submitted with
	 * @param array $alldata The data collected from the form
	 *
	 * @return bool True to cancel the submission
	 */
	public function cancelSubmit( $value, $alldata ) {
		return false;
	}

	/**
	 * Override this function to add specific validation checks on the
	 * field input.  Don't forget to call parent::validate() to ensure
	 * that the user-defined callback mValidationCallback is still run
	 * @stable to override
	 *
	 * @param string|array $value The value the field was submitted with
	 * @param array $alldata The data collected from the form
	 *
	 * @return bool|string|Message True on success, or String/Message error to display, or
	 *   false to fail validation without displaying an error.
	 */
	public function validate( $value, $alldata ) {
		if ( $this->isHidden( $alldata ) ) {
			return true;
		}

		if ( isset( $this->mParams['required'] )
			&& $this->mParams['required'] !== false
			&& $value === ''
		) {
			return $this->msg( 'htmlform-required' );
		}

		if ( isset( $this->mValidationCallback ) ) {
			return ( $this->mValidationCallback )( $value, $alldata, $this->mParent );
		}

		return true;
	}

	/**
	 * @stable to override
	 *
	 * @param mixed $value
	 * @param HTMLFormField[] $alldata
	 *
	 * @return mixed
	 */
	public function filter( $value, $alldata ) {
		if ( isset( $this->mFilterCallback ) ) {
			$value = ( $this->mFilterCallback )( $value, $alldata, $this->mParent );
		}

		return $value;
	}

	/**
	 * Should this field have a label, or is there no input element with the
	 * appropriate id for the label to point to?
	 * @stable to override
	 *
	 * @return bool True to output a label, false to suppress
	 */
	protected function needsLabel() {
		return true;
	}

	/**
	 * Tell the field whether to generate a separate label element if its label
	 * is blank.
	 *
	 * @since 1.22
	 *
	 * @param bool $show Set to false to not generate a label.
	 * @return void
	 */
	public function setShowEmptyLabel( $show ) {
		$this->mShowEmptyLabels = $show;
	}

	/**
	 * Can we assume that the request is an attempt to submit a HTMLForm, as opposed to an attempt to
	 * just view it? This can't normally be distinguished for e.g. checkboxes.
	 *
	 * Returns true if the request has a field for a CSRF token (wpEditToken) or a form identifier
	 * (wpFormIdentifier).
	 *
	 * @param WebRequest $request
	 * @return bool
	 */
	protected function isSubmitAttempt( WebRequest $request ) {
		return $request->getCheck( 'wpEditToken' ) || $request->getCheck( 'wpFormIdentifier' );
	}

	/**
	 * Get the value that this input has been set to from a posted form,
	 * or the input's default value if it has not been set.
	 * @stable to override
	 *
	 * @param WebRequest $request
	 * @return mixed The value
	 */
	public function loadDataFromRequest( $request ) {
		if ( $request->getCheck( $this->mName ) ) {
			return $request->getText( $this->mName );
		} else {
			return $this->getDefault();
		}
	}

	/**
	 * Initialise the object
	 *
	 * @stable to call
	 * @param array $params Associative Array. See HTMLForm doc for syntax.
	 *
	 * @since 1.22 The 'label' attribute no longer accepts raw HTML, use 'label-raw' instead
	 * @throws MWException
	 */
	public function __construct( $params ) {
		$this->mParams = $params;

		if ( isset( $params['parent'] ) && $params['parent'] instanceof HTMLForm ) {
			$this->mParent = $params['parent'];
		}

		# Generate the label from a message, if possible
		if ( isset( $params['label-message'] ) ) {
			$this->mLabel = $this->getMessage( $params['label-message'] )->parse();
		} elseif ( isset( $params['label'] ) ) {
			if ( $params['label'] === '&#160;' || $params['label'] === "\u{00A0}" ) {
				// Apparently some things set &nbsp directly and in an odd format
				$this->mLabel = "\u{00A0}";
			} else {
				$this->mLabel = htmlspecialchars( $params['label'] );
			}
		} elseif ( isset( $params['label-raw'] ) ) {
			$this->mLabel = $params['label-raw'];
		}

		$this->mName = "wp{$params['fieldname']}";
		if ( isset( $params['name'] ) ) {
			$this->mName = $params['name'];
		}

		if ( isset( $params['dir'] ) ) {
			$this->mDir = $params['dir'];
		}

		$this->mID = "mw-input-{$this->mName}";

		if ( isset( $params['default'] ) ) {
			$this->mDefault = $params['default'];
		}

		if ( isset( $params['id'] ) ) {
			$this->mID = $params['id'];
		}

		if ( isset( $params['cssclass'] ) ) {
			$this->mClass = $params['cssclass'];
		}

		if ( isset( $params['csshelpclass'] ) ) {
			$this->mHelpClass = $params['csshelpclass'];
		}

		if ( isset( $params['validation-callback'] ) ) {
			$this->mValidationCallback = $params['validation-callback'];
		}

		if ( isset( $params['filter-callback'] ) ) {
			$this->mFilterCallback = $params['filter-callback'];
		}

		if ( isset( $params['hidelabel'] ) ) {
			$this->mShowEmptyLabels = false;
		}

		if ( isset( $params['hide-if'] ) && $params['hide-if'] ) {
			$this->mCondState['hide'] = $params['hide-if'];
			$this->mCondState['class'][] = 'mw-htmlform-hide-if';
		}
		if ( !( isset( $params['disabled'] ) && $params['disabled'] ) &&
			isset( $params['disable-if'] ) && $params['disable-if']
		) {
			$this->mCondState['disable'] = $params['disable-if'];
			$this->mCondState['class'][] = 'mw-htmlform-disable-if';
		}
	}

	/**
	 * Get the complete table row for the input, including help text,
	 * labels, and whatever.
	 * @stable to override
	 *
	 * @param string $value The value to set the input to.
	 *
	 * @return string Complete HTML table row.
	 */
	public function getTableRow( $value ) {
		list( $errors, $errorClass ) = $this->getErrorsAndErrorClass( $value );
		$inputHtml = $this->getInputHTML( $value );
		$fieldType = $this->getClassName();
		$helptext = $this->getHelpTextHtmlTable( $this->getHelpText() );
		$cellAttributes = [];
		$rowAttributes = [];
		$rowClasses = '';

		if ( !empty( $this->mParams['vertical-label'] ) ) {
			$cellAttributes['colspan'] = 2;
			$verticalLabel = true;
		} else {
			$verticalLabel = false;
		}

		$label = $this->getLabelHtml( $cellAttributes );

		$field = Html::rawElement(
			'td',
			[ 'class' => 'mw-input' ] + $cellAttributes,
			$inputHtml . "\n$errors"
		);

		if ( $this->mCondState['class'] ) {
			$rowAttributes['data-cond-state'] = FormatJson::encode( $this->mCondState );
			$rowClasses = implode( ' ', $this->mCondState['class'] );
		}

		if ( $verticalLabel ) {
			$html = Html::rawElement( 'tr',
				$rowAttributes + [ 'class' => "mw-htmlform-vertical-label $rowClasses" ], $label );
			$html .= Html::rawElement( 'tr',
				$rowAttributes + [
					'class' => "mw-htmlform-field-$fieldType {$this->mClass} $errorClass $rowClasses"
				],
				$field );
		} else {
			$html = Html::rawElement( 'tr',
				$rowAttributes + [
					'class' => "mw-htmlform-field-$fieldType {$this->mClass} $errorClass $rowClasses"
				],
				$label . $field );
		}

		return $html . $helptext;
	}

	/**
	 * Get the complete div for the input, including help text,
	 * labels, and whatever.
	 * @stable to override
	 * @since 1.20
	 *
	 * @param string $value The value to set the input to.
	 *
	 * @return string Complete HTML table row.
	 */
	public function getDiv( $value ) {
		list( $errors, $errorClass ) = $this->getErrorsAndErrorClass( $value );
		$inputHtml = $this->getInputHTML( $value );
		$fieldType = $this->getClassName();
		$helptext = $this->getHelpTextHtmlDiv( $this->getHelpText() );
		$cellAttributes = [];
		$label = $this->getLabelHtml( $cellAttributes );

		$outerDivClass = [
			'mw-input',
			'mw-htmlform-nolabel' => ( $label === '' )
		];

		$horizontalLabel = $this->mParams['horizontal-label'] ?? false;

		if ( $horizontalLabel ) {
			$field = "\u{00A0}" . $inputHtml . "\n$errors";
		} else {
			$field = Html::rawElement(
				'div',
				// @phan-suppress-next-line PhanUselessBinaryAddRight
				[ 'class' => $outerDivClass ] + $cellAttributes,
				$inputHtml . "\n$errors"
			);
		}
		$divCssClasses = [ "mw-htmlform-field-$fieldType",
			$this->mClass, $this->mVFormClass, $errorClass ];

		$wrapperAttributes = [
			'class' => $divCssClasses,
		];
		if ( $this->mCondState['class'] ) {
			$wrapperAttributes['data-cond-state'] = FormatJson::encode( $this->mCondState );
			$wrapperAttributes['class'] += $this->mCondState['class'];
		}
		$html = Html::rawElement( 'div', $wrapperAttributes, $label . $field );
		$html .= $helptext;

		return $html;
	}

	/**
	 * Get the OOUI version of the div. Falls back to getDiv by default.
	 * @stable to override
	 * @since 1.26
	 *
	 * @param string $value The value to set the input to.
	 *
	 * @return OOUI\FieldLayout|OOUI\ActionFieldLayout
	 */
	public function getOOUI( $value ) {
		$inputField = $this->getInputOOUI( $value );

		if ( !$inputField ) {
			// This field doesn't have an OOUI implementation yet at all. Fall back to getDiv() to
			// generate the whole field, label and errors and all, then wrap it in a Widget.
			// It might look weird, but it'll work OK.
			return $this->getFieldLayoutOOUI(
				new OOUI\Widget( [ 'content' => new OOUI\HtmlSnippet( $this->getDiv( $value ) ) ] ),
				[ 'align' => 'top' ]
			);
		}

		$infusable = true;
		if ( is_string( $inputField ) ) {
			// We have an OOUI implementation, but it's not proper, and we got a load of HTML.
			// Cheat a little and wrap it in a widget. It won't be infusable, though, since client-side
			// JavaScript doesn't know how to rebuilt the contents.
			$inputField = new OOUI\Widget( [ 'content' => new OOUI\HtmlSnippet( $inputField ) ] );
			$infusable = false;
		}

		$fieldType = $this->getClassName();
		$help = $this->getHelpText();
		$errors = $this->getErrorsRaw( $value );
		foreach ( $errors as &$error ) {
			$error = new OOUI\HtmlSnippet( $error );
		}

		$config = [
			'classes' => [ "mw-htmlform-field-$fieldType", $this->mClass ],
			'align' => $this->getLabelAlignOOUI(),
			'help' => ( $help !== null && $help !== '' ) ? new OOUI\HtmlSnippet( $help ) : null,
			'errors' => $errors,
			'infusable' => $infusable,
			'helpInline' => $this->isHelpInline(),
		];

		$preloadModules = false;

		if ( $infusable && $this->shouldInfuseOOUI() ) {
			$preloadModules = true;
			$config['classes'][] = 'mw-htmlform-autoinfuse';
		}

		// the element could specify, that the label doesn't need to be added
		$label = $this->getLabel();
		if ( $label && $label !== "\u{00A0}" && $label !== '&#160;' ) {
			$config['label'] = new OOUI\HtmlSnippet( $label );
		}

		if ( $this->mCondState['class'] ) {
			$preloadModules = true;
			$config['condState'] = $this->mCondState;
		}

		$config['modules'] = $this->getOOUIModules();

		if ( $preloadModules ) {
			$this->mParent->getOutput()->addModules( 'mediawiki.htmlform.ooui' );
			$this->mParent->getOutput()->addModules( $this->getOOUIModules() );
		}

		return $this->getFieldLayoutOOUI( $inputField, $config );
	}

	/**
	 * Gets the non namespaced class name
	 *
	 * @since 1.36
	 *
	 * @return string
	 */
	protected function getClassName() {
		$name = explode( '\\', static::class );
		return end( $name );
	}

	/**
	 * Get label alignment when generating field for OOUI.
	 * @stable to override
	 * @return string 'left', 'right', 'top' or 'inline'
	 */
	protected function getLabelAlignOOUI() {
		return 'top';
	}

	/**
	 * Get a FieldLayout (or subclass thereof) to wrap this field in when using OOUI output.
	 * @param OOUI\Widget $inputField
	 * @param array $config
	 * @return OOUI\FieldLayout|OOUI\ActionFieldLayout
	 * @suppress PhanUndeclaredProperty Only some subclasses declare mClassWithButton
	 */
	protected function getFieldLayoutOOUI( $inputField, $config ) {
		if ( isset( $this->mClassWithButton ) ) {
			$buttonWidget = $this->mClassWithButton->getInputOOUI( '' );
			return new HTMLFormActionFieldLayout( $inputField, $buttonWidget, $config );
		}
		return new HTMLFormFieldLayout( $inputField, $config );
	}

	/**
	 * Whether the field should be automatically infused. Note that all OOUI HTMLForm fields are
	 * infusable (you can call OO.ui.infuse() on them), but not all are infused by default, since
	 * there is no benefit in doing it e.g. for buttons and it's a small performance hit on page load.
	 * @stable to override
	 *
	 * @return bool
	 */
	protected function shouldInfuseOOUI() {
		// Always infuse fields with popup help text, since the interface for it is nicer with JS
		return $this->getHelpText() !== null && !$this->isHelpInline();
	}

	/**
	 * Get the list of extra ResourceLoader modules which must be loaded client-side before it's
	 * possible to infuse this field's OOUI widget.
	 * @stable to override
	 *
	 * @return string[]
	 */
	protected function getOOUIModules() {
		return [];
	}

	/**
	 * Get the complete raw fields for the input, including help text,
	 * labels, and whatever.
	 * @stable to override
	 * @since 1.20
	 *
	 * @param string $value The value to set the input to.
	 *
	 * @return string Complete HTML table row.
	 */
	public function getRaw( $value ) {
		list( $errors, ) = $this->getErrorsAndErrorClass( $value );
		$inputHtml = $this->getInputHTML( $value );
		$helptext = $this->getHelpTextHtmlRaw( $this->getHelpText() );
		$cellAttributes = [];
		$label = $this->getLabelHtml( $cellAttributes );

		$html = "\n$errors";
		$html .= $label;
		$html .= $inputHtml;
		$html .= $helptext;

		return $html;
	}

	/**
	 * Get the complete field for the input, including help text,
	 * labels, and whatever. Fall back from 'vform' to 'div' when not overridden.
	 *
	 * @stable to override
	 * @since 1.25
	 * @param string $value The value to set the input to.
	 * @return string Complete HTML field.
	 */
	public function getVForm( $value ) {
		// Ewwww
		$this->mVFormClass = ' mw-ui-vform-field';
		return $this->getDiv( $value );
	}

	/**
	 * Get the complete field as an inline element.
	 * @stable to override
	 * @since 1.25
	 * @param string $value The value to set the input to.
	 * @return string Complete HTML inline element
	 */
	public function getInline( $value ) {
		list( $errors, $errorClass ) = $this->getErrorsAndErrorClass( $value );
		$inputHtml = $this->getInputHTML( $value );
		$helptext = $this->getHelpTextHtmlDiv( $this->getHelpText() );
		$cellAttributes = [];
		$label = $this->getLabelHtml( $cellAttributes );

		$html = "\n" . $errors .
			$label . "\u{00A0}" .
			$inputHtml .
			$helptext;

		return $html;
	}

	/**
	 * Generate help text HTML in table format
	 * @since 1.20
	 *
	 * @param string|null $helptext
	 * @return string
	 */
	public function getHelpTextHtmlTable( $helptext ) {
		if ( $helptext === null ) {
			return '';
		}

		$rowAttributes = [];
		if ( $this->mCondState['class'] ) {
			$rowAttributes['data-cond-state'] = FormatJson::encode( $this->mCondState );
			$rowAttributes['class'] = $this->mCondState['class'];
		}

		$tdClasses = [ 'htmlform-tip' ];
		if ( $this->mHelpClass !== false ) {
			$tdClasses[] = $this->mHelpClass;
		}
		$row = Html::rawElement( 'td', [ 'colspan' => 2, 'class' => $tdClasses ], $helptext );
		$row = Html::rawElement( 'tr', $rowAttributes, $row );

		return $row;
	}

	/**
	 * Generate help text HTML in div format
	 * @since 1.20
	 *
	 * @param string|null $helptext
	 *
	 * @return string
	 */
	public function getHelpTextHtmlDiv( $helptext ) {
		if ( $helptext === null ) {
			return '';
		}

		$wrapperAttributes = [
			'class' => [ 'htmlform-tip' ],
		];
		if ( $this->mHelpClass !== false ) {
			$wrapperAttributes['class'][] = $this->mHelpClass;
		}
		if ( $this->mCondState['class'] ) {
			$wrapperAttributes['data-cond-state'] = FormatJson::encode( $this->mCondState );
			$wrapperAttributes['class'] += $this->mCondState['class'];
		}
		$div = Html::rawElement( 'div', $wrapperAttributes, $helptext );

		return $div;
	}

	/**
	 * Generate help text HTML formatted for raw output
	 * @since 1.20
	 *
	 * @param string|null $helptext
	 * @return string
	 */
	public function getHelpTextHtmlRaw( $helptext ) {
		return $this->getHelpTextHtmlDiv( $helptext );
	}

	/**
	 * Determine the help text to display
	 * @stable to override
	 * @since 1.20
	 * @return string|null HTML
	 */
	public function getHelpText() {
		$helptext = null;

		if ( isset( $this->mParams['help-message'] ) ) {
			$this->mParams['help-messages'] = [ $this->mParams['help-message'] ];
		}

		if ( isset( $this->mParams['help-messages'] ) ) {
			foreach ( $this->mParams['help-messages'] as $msg ) {
				$msg = $this->getMessage( $msg );

				if ( $msg->exists() ) {
					if ( $helptext === null ) {
						$helptext = '';
					} else {
						$helptext .= $this->msg( 'word-separator' )->escaped(); // some space
					}
					$helptext .= $msg->parse(); // Append message
				}
			}
		} elseif ( isset( $this->mParams['help'] ) ) {
			$helptext = $this->mParams['help'];
		}

		return $helptext;
	}

	/**
	 * Determine if the help text should be displayed inline.
	 *
	 * Only applies to OOUI forms.
	 *
	 * @since 1.31
	 * @return bool
	 */
	public function isHelpInline() {
		return $this->mParams['help-inline'] ?? true;
	}

	/**
	 * Determine form errors to display and their classes
	 * @since 1.20
	 *
	 * phan-taint-check gets confused with returning both classes
	 * and errors and thinks double escaping is happening, so specify
	 * that return value has no taint.
	 *
	 * @param string $value The value of the input
	 * @return array [ $errors, $errorClass ]
	 * @return-taint none
	 */
	public function getErrorsAndErrorClass( $value ) {
		$errors = $this->validate( $value, $this->mParent->mFieldData );

		if ( is_bool( $errors ) || !$this->mParent->wasSubmitted() ) {
			$errors = '';
			$errorClass = '';
		} else {
			$errors = self::formatErrors( $errors );
			$errorClass = 'mw-htmlform-invalid-input';
		}

		return [ $errors, $errorClass ];
	}

	/**
	 * Determine form errors to display, returning them in an array.
	 *
	 * @since 1.26
	 * @param string $value The value of the input
	 * @return string[] Array of error HTML strings
	 */
	public function getErrorsRaw( $value ) {
		$errors = $this->validate( $value, $this->mParent->mFieldData );

		if ( is_bool( $errors ) || !$this->mParent->wasSubmitted() ) {
			$errors = [];
		}

		if ( !is_array( $errors ) ) {
			$errors = [ $errors ];
		}
		foreach ( $errors as &$error ) {
			if ( $error instanceof Message ) {
				$error = $error->parse();
			}
		}

		return $errors;
	}

	/**
	 * @stable to override
	 * @return string HTML
	 */
	public function getLabel() {
		return $this->mLabel ?? '';
	}

	/**
	 * @stable to override
	 * @param array $cellAttributes
	 *
	 * @return string
	 */
	public function getLabelHtml( $cellAttributes = [] ) {
		# Don't output a for= attribute for labels with no associated input.
		# Kind of hacky here, possibly we don't want these to be <label>s at all.
		$for = [];

		if ( $this->needsLabel() ) {
			$for['for'] = $this->mID;
		}

		$labelValue = trim( $this->getLabel() );
		$hasLabel = false;
		if ( $labelValue !== "\u{00A0}" && $labelValue !== '&#160;' && $labelValue !== '' ) {
			$hasLabel = true;
		}

		$displayFormat = $this->mParent->getDisplayFormat();
		$html = '';
		$horizontalLabel = $this->mParams['horizontal-label'] ?? false;

		if ( $displayFormat === 'table' ) {
			$html =
				Html::rawElement( 'td',
					[ 'class' => 'mw-label' ] + $cellAttributes,
					Html::rawElement( 'label', $for, $labelValue ) );
		} elseif ( $hasLabel || $this->mShowEmptyLabels ) {
			if ( $displayFormat === 'div' && !$horizontalLabel ) {
				$html =
					Html::rawElement( 'div',
						[ 'class' => 'mw-label' ] + $cellAttributes,
						Html::rawElement( 'label', $for, $labelValue ) );
			} else {
				$html = Html::rawElement( 'label', $for, $labelValue );
			}
		}

		return $html;
	}

	/**
	 * @stable to override
	 * @return mixed
	 */
	public function getDefault() {
		return $this->mDefault ?? null;
	}

	/**
	 * Returns the attributes required for the tooltip and accesskey, for Html::element() etc.
	 *
	 * @return array Attributes
	 */
	public function getTooltipAndAccessKey() {
		if ( empty( $this->mParams['tooltip'] ) ) {
			return [];
		}

		return Linker::tooltipAndAccesskeyAttribs( $this->mParams['tooltip'] );
	}

	/**
	 * Returns the attributes required for the tooltip and accesskey, for OOUI widgets' config.
	 *
	 * @return array Attributes
	 */
	public function getTooltipAndAccessKeyOOUI() {
		if ( empty( $this->mParams['tooltip'] ) ) {
			return [];
		}

		return [
			'title' => Linker::titleAttrib( $this->mParams['tooltip'] ),
			'accessKey' => Linker::accesskey( $this->mParams['tooltip'] ),
		];
	}

	/**
	 * Returns the given attributes from the parameters
	 * @stable to override
	 *
	 * @param array $list List of attributes to get
	 * @return array Attributes
	 */
	public function getAttributes( array $list ) {
		static $boolAttribs = [ 'disabled', 'required', 'autofocus', 'multiple', 'readonly' ];

		$ret = [];
		foreach ( $list as $key ) {
			if ( in_array( $key, $boolAttribs ) ) {
				if ( !empty( $this->mParams[$key] ) ) {
					$ret[$key] = '';
				}
			} elseif ( isset( $this->mParams[$key] ) ) {
				$ret[$key] = $this->mParams[$key];
			}
		}

		return $ret;
	}

	/**
	 * Given an array of msg-key => value mappings, returns an array with keys
	 * being the message texts. It also forces values to strings.
	 *
	 * @param array $options
	 * @param bool $needsParse
	 * @return array
	 * @return-taint tainted
	 */
	private function lookupOptionsKeys( $options, $needsParse ) {
		$ret = [];
		foreach ( $options as $key => $value ) {
			$msg = $this->msg( $key );
			$key = $needsParse ? $msg->parse() : $msg->plain();
			$ret[$key] = is_array( $value )
				? $this->lookupOptionsKeys( $value, $needsParse )
				: strval( $value );
		}
		return $ret;
	}

	/**
	 * Recursively forces values in an array to strings, because issues arise
	 * with integer 0 as a value.
	 *
	 * @param array|string $array
	 * @return array|string
	 */
	public static function forceToStringRecursive( $array ) {
		if ( is_array( $array ) ) {
			return array_map( [ __CLASS__, 'forceToStringRecursive' ], $array );
		} else {
			return strval( $array );
		}
	}

	/**
	 * Fetch the array of options from the field's parameters. In order, this
	 * checks 'options-messages', 'options', then 'options-message'.
	 *
	 * @return array|null
	 */
	public function getOptions() {
		if ( $this->mOptions === false ) {
			if ( array_key_exists( 'options-messages', $this->mParams ) ) {
				$needsParse = $this->mParams['options-messages-parse'] ?? false;
				if ( $needsParse ) {
					$this->mOptionsLabelsNotFromMessage = true;
				}
				$this->mOptions = $this->lookupOptionsKeys( $this->mParams['options-messages'], $needsParse );
			} elseif ( array_key_exists( 'options', $this->mParams ) ) {
				$this->mOptionsLabelsNotFromMessage = true;
				$this->mOptions = self::forceToStringRecursive( $this->mParams['options'] );
			} elseif ( array_key_exists( 'options-message', $this->mParams ) ) {
				$message = $this->getMessage( $this->mParams['options-message'] )->inContentLanguage()->plain();
				$this->mOptions = Xml::listDropDownOptions( $message );
			} else {
				$this->mOptions = null;
			}
		}

		return $this->mOptions;
	}

	/**
	 * Get options and make them into arrays suitable for OOUI.
	 * @stable to override
	 * @return array Options for inclusion in a select or whatever.
	 */
	public function getOptionsOOUI() {
		$oldoptions = $this->getOptions();

		if ( $oldoptions === null ) {
			return null;
		}

		return Xml::listDropDownOptionsOoui( $oldoptions );
	}

	/**
	 * flatten an array of options to a single array, for instance,
	 * a set of "<options>" inside "<optgroups>".
	 *
	 * @param array $options Associative Array with values either Strings or Arrays
	 * @return array Flattened input
	 */
	public static function flattenOptions( $options ) {
		$flatOpts = [];

		foreach ( $options as $value ) {
			if ( is_array( $value ) ) {
				$flatOpts = array_merge( $flatOpts, self::flattenOptions( $value ) );
			} else {
				$flatOpts[] = $value;
			}
		}

		return $flatOpts;
	}

	/**
	 * Formats one or more errors as accepted by field validation-callback.
	 *
	 * @param string|Message|array $errors Array of strings or Message instances
	 * To work around limitations in phan-taint-check the calling
	 * class has taintedness disabled. So instead we pretend that
	 * this method outputs html, since the result is eventually
	 * outputted anyways without escaping and this allows us to verify
	 * stuff is safe even though the caller has taintedness cleared.
	 * @param-taint $errors exec_html
	 * @return string HTML
	 * @since 1.18
	 */
	protected static function formatErrors( $errors ) {
		if ( is_array( $errors ) && count( $errors ) === 1 ) {
			$errors = array_shift( $errors );
		}

		if ( is_array( $errors ) ) {
			$lines = [];
			foreach ( $errors as $error ) {
				if ( $error instanceof Message ) {
					$lines[] = Html::rawElement( 'li', [], $error->parse() );
				} else {
					$lines[] = Html::rawElement( 'li', [], $error );
				}
			}

			$errors = Html::rawElement( 'ul', [], implode( "\n", $lines ) );
		} else {
			if ( $errors instanceof Message ) {
				$errors = $errors->parse();
			}
		}

		return Html::rawElement( 'div', [ 'class' => 'errorbox' ], $errors );
	}

	/**
	 * Turns a *-message parameter (which could be a MessageSpecifier, or a message name, or a
	 * name + parameters array) into a Message.
	 * @param mixed $value
	 * @return Message
	 */
	protected function getMessage( $value ) {
		$message = Message::newFromSpecifier( $value );

		if ( $this->mParent ) {
			$message->setContext( $this->mParent );
		}

		return $message;
	}

	/**
	 * Skip this field when collecting data.
	 * @stable to override
	 * @param WebRequest $request
	 * @return bool
	 * @since 1.27
	 */
	public function skipLoadData( $request ) {
		return !empty( $this->mParams['nodata'] );
	}

	/**
	 * Whether this field requires the user agent to have JavaScript enabled for the client-side HTML5
	 * form validation to work correctly.
	 *
	 * @return bool
	 * @since 1.29
	 */
	public function needsJSForHtml5FormValidation() {
		if ( $this->mCondState['class'] ) {
			// This is probably more restrictive than it needs to be, but better safe than sorry
			return true;
		}
		return false;
	}
}
