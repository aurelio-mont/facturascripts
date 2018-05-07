<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2014-2018  Carlos Garcia Gomez  <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Core\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * A VAT regularization.
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 * @author Artex Trading sa <jcuello@artextrading.com>
 */
class RegularizacionImpuesto extends Base\ModelClass
{

    use Base\ModelTrait;

    /**
     * Primary key.
     *
     * @var int
     */
    public $idregularizacion;

    /**
     * Exercise code.
     *
     * @var string
     */
    public $codejercicio;

    /**
     * Period of regularization.
     *
     * @var string
     */
    public $periodo;

    /**
     * Start date.
     *
     * @var string
     */
    public $fechainicio;

    /**
     * End date.
     *
     * @var string
     */
    public $fechafin;

    /**
     * Related sub-account ID.
     *
     * @var int
     */
    public $idsubcuentaacreedora;

    /**
     * Code, not ID, of the related sub-account.
     *
     * @var string
     */
    public $codsubcuentaacreedora;

    /**
     * Related sub-account ID.
     *
     * @var int
     */
    public $idsubcuentadeudora;

    /**
     * Code, not ID, of the related sub-account.
     *
     * @var string
     */
    public $codsubcuentadeudora;

    /**
     * ID of the generated accounting entry.
     *
     * @var int
     */
    public $idasiento;

    /**
     * Date of entry.
     *
     * @var string
     */
    public $fechaasiento;

    /**
     * Returns the name of the table that uses this model.
     *
     * @return string
     */
    public static function tableName()
    {
        return 'regularizacionimpuestos';
    }

    /**
     * Returns the name of the column that is the model's primary key.
     *
     * @return string
     */
    public static function primaryColumn()
    {
        return 'idregularizacion';
    }

    /**
     * Returns the description for model data.
     *
     * @return string
     */
    public function primaryDescription()
    {
        return $this->codejercicio . ' - ' . $this->periodo;
    }

    public function clear()
    {
        parent::clear();

        // Search open exercise for current date
        $exerciseModel = new Ejercicio();
        $exercise = $exerciseModel->getByFecha(date('d-m-Y'), true, false);
        $this->codejercicio = $exercise->codejercicio;
        $this->periodo = 'T1';

        $period = $this->getPeriod($this->periodo, $exercise->fechainicio, false);
        $this->fechainicio = $period['start'];
        $this->fechafin = $period['end'];

        $account = new Subcuenta();
        $where1 = [
            new DataBaseWhere('codejercicio', $this->codejercicio),
            new DataBaseWhere('codcuentaesp', 'IVAACR')
        ];
        if ($account->loadFromCode(null, $where1)) {
            $this->idsubcuentaacreedora = $account->idsubcuenta;
            $this->codsubcuentaacreedora = $account->codsubcuenta;
        }

        $where2 = [
            new DataBaseWhere('codejercicio', $this->codejercicio),
            new DataBaseWhere('codcuentaesp', 'IVADEU')
        ];
        if ($account->loadFromCode(null, $where2)) {
            $this->idsubcuentadeudora = $account->idsubcuenta;
            $this->codsubcuentadeudora = $account->codsubcuenta;
        }
    }

    /**
     * Returns the items per accounting entry.
     *
     * @return Partida[]|bool
     */
    public function getPartidas()
    {
        if ($this->idasiento !== null) {
            $partida = new Partida();

            return $partida->all([new DataBaseWhere('idasiento', $this->idasiento)]);
        }

        return false;
    }

    /**
     * Returns the VAT regularization corresponding to that date,
           * that is, the regularization whose start date is earlier
           * to the date provided and its end date is after the date
           * provided. So you can know if the period is still open to be able
           * check in.
     *
     * @param string $fecha
     *
     * @return bool|RegularizacionImpuestos
     */
    public function getFechaInside($fecha)
    {
        $sql = 'SELECT * FROM ' . static::tableName() . ' WHERE fechainicio <= ' . self::$dataBase->var2str($fecha)
            . ' AND fechafin >= ' . self::$dataBase->var2str($fecha) . ';';

        $data = self::$dataBase->select($sql);
        if (!empty($data)) {
            return new self($data[0]);
        }

        return false;
    }

    /**
     * Deletes the regularization of VAT from the database.
     *
     * @return bool
     */
    public function delete()
    {
        // TODO: Apply transactions
        $sql = 'DELETE FROM ' . static::tableName()
            . ' WHERE idregularizacion = ' . self::$dataBase->var2str($this->idregularizacion) . ';';
        if (self::$dataBase->exec($sql)) {
            /// si hay un asiento asociado lo eliminamos
            if ($this->idasiento !== null) {
                $asientoModel = new Asiento();
                $asiento = $asientoModel->get($this->idasiento);
                if ($asiento) {
                    $asiento->delete();
                }
            }

            return true;
        }

        return false;
    }

    private function getPeriod($period, $date = null, $add = false): array
    {
        /// Calculate next period
        if (!empty($period) && $add) {
            $period = 'T' . ((int) substr($period, -1) + 1);
        }

        /// Calculate actual year
        if (empty($date)) {
            $date = date('d-m-Y');
        }
        $year = explode('-', $date)[2];

        /// Init values
        $result = [
            'period' => 'T1',
            'start' => date('01-01-' . $year),
            'end' => date('31-03-' . $year)
        ];

        // For valids periods set values
        switch ($period) {
            case 'T2':
                $result['period'] = 'T2';
                $result['start'] = '01-04-' . $year;
                $result['end'] = '30-06-' . $year;
                break;

            case 'T3':
                $result['period'] = 'T3';
                $result['start'] = '01-07-' . $year;
                $result['end'] = '30-09-' . $year;
                break;

            case 'T4':
                $result['period'] = 'T4';
                $result['start'] = '01-10-' . $year;
                $result['end'] = '31-12-' . $year;
                break;
        }
        return $result;
    }

    public function loadNextPeriod()
    {
        $where = [ new DataBaseWhere('codejercicio', $this->codejercicio) ];
        $regularization = new self();
        $regularization->loadFromCode(null, $where, [ 'periodo' => 'DESC' ]);
        if (empty($regularization->codejercicio)) {
            return;
        }

        /// Load next period regularization values
        $exercise = new Ejercicio();
        $exercise->loadFromCode($regularization->codejercicio);
        $period = $this->getPeriod($regularization->periodo, $exercise->fechainicio, true);

        $this->codejercicio = $exercise->codejercicio;
        $this->periodo = $period['period'];
        $this->fechainicio = $period['start'];
        $this->fechafin = $period['end'];
        $this->idsubcuentaacreedora = $regularization->idsubcuentaacreedora;
        $this->codsubcuentaacreedora = $regularization->codsubcuentaacreedora;
        $this->idsubcuentadeudora = $regularization->idsubcuentadeudora;
        $this->codsubcuentadeudora = $regularization->codsubcuentadeudora;
    }

    public function test()
    {
        if (!parent::test()) {
            return false;
        }

        /// Calculate dates to selected period
        $exercise = new Ejercicio();
        $exercise->loadFromCode($this->codejercicio);
        $period = $this->getPeriod($this->periodo, $exercise->fechainicio, false);

        $this->fechainicio = $period['start'];
        $this->fechafin = $period['end'];

        return true;
    }
}
