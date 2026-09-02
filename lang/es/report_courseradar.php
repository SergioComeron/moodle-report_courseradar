<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Spanish language strings for report_courseradar.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aboveaverage']        = 'Por encima de la media';
$string['activityovertime']    = 'Actividad a lo largo del tiempo';
$string['activityovertime_desc'] = 'Evolución diaria de las interacciones de los alumnos en el período seleccionado.';
$string['activitypattern']     = 'Patrón de actividad (día x hora)';
$string['activitypattern_desc'] = 'Interacciones agrupadas por día de la semana y franja horaria — muestra cuándo estudian más los alumnos.';
$string['adjustperiod']        = 'Ajustar período';
$string['allstudentsviewed']   = '¡Todos los estudiantes han visto este recurso!';
$string['allvisited']          = '¡Bien hecho! Has abierto todos los recursos disponibles.';
$string['analyzingperiod']     = 'Período analizado: {$a->from} – {$a->to}';
$string['applyfilter']         = 'Aplicar filtro';
$string['atrisk']              = 'Alumnos con baja actividad';
$string['atrisk_info']         = 'Alumnos sin interacciones o con muy poca actividad en el período seleccionado.';
$string['atrisk_lowactivity']  = 'Participación baja (< 30% recursos visitados)';
$string['atrisk_noactivity']   = 'Sin ninguna interacción';
$string['avgdedication']       = 'Tiempo medio de conexión';
$string['avgdedication_desc']  = 'Por alumno con sesiones registradas';
$string['avgengagement']       = 'Participación media';
$string['avgviews'] = 'Media de vistas / módulo';
$string['baseurl']             = 'URLs base de la API';
$string['baseurl_desc']        = 'Una URL por línea. Solo se usa si no se reutilizan las credenciales de Zoom UDIMA.';
$string['belowaverage']        = 'Por debajo de la media';
$string['campus']              = 'Campus';
$string['campus_desc']         = 'Se envía como Origen. UDIMA usa el username de Moodle; CEF el idnumber.';
$string['chartdateformat']     = '%d %b';
$string['classaverage']        = 'Media de la clase';
$string['clientid']            = 'OAuth client ID';
$string['clientid_desc']       = 'Opcional si se reutilizan las credenciales de Zoom UDIMA.';
$string['clientsecret']        = 'OAuth client secret';
$string['clientsecret_desc']   = 'Opcional si se reutilizan las credenciales de Zoom UDIMA.';
$string['compareclass']        = 'Cómo te comparas con la clase';
$string['compareclass_desc']   = 'Tus cifras junto a la media (anónima) de la clase. Nunca se identifica a otros estudiantes.';
$string['completednoftrack']   = 'Sin rastreo de finalización';
$string['completion']          = 'Finalización';
$string['completion_desc']     = 'Alumnos que lo han completado';
$string['completiondisabled']  = 'La finalización de actividades no está habilitada en este curso.';
$string['completionstu']       = 'Finalización';
$string['completionstu_desc']  = 'Completadas / con seguimiento';
$string['conexiones']          = 'Conexiones en directo / diferido';
$string['conexiones_desc']     = 'Tiempo en sesiones Zoom en directo y visualizaciones Vimeo en diferido (API UDIMA).';
$string['conexionesapiheading'] = 'API de conexiones (Zoom / Vimeo)';
$string['conexionesapiheading_desc'] = 'POST /Informes/GetInformeConexionesAlumno. Por defecto se reutiliza el OAuth del bloque Zoom UDIMA.';
$string['conexionesdelayed']   = 'Diferido (Vimeo)';
$string['conexionesdelayed_desc'] = 'Visualizaciones de las grabaciones';
$string['conexioneserror']     = 'No se han podido cargar las conexiones';
$string['conexioneslive']      = 'Directo (Zoom)';
$string['conexioneslive_desc'] = 'Tiempo conectado a las sesiones en directo';
$string['conexionesnone']      = 'Sin datos de conexión';
$string['conexionesreusezoom'] = 'Reutilizar credenciales de Zoom UDIMA';
$string['conexionesreusezoom_desc'] = 'Usar el token OAuth, las URLs y el campus ya configurados en block_zoom_udima.';
$string['conexionesunavailable'] = 'La API de conexiones no está configurada';
$string['courseradar:view']    = 'Ver informe Course Radar';
$string['coursevisits']        = 'Visitas al curso';
$string['coursevisits_desc']   = 'Veces que los alumnos han accedido al curso';
$string['coverage']            = 'Cobertura';
$string['coverage_desc']       = '% alumnos matriculados que lo han visto';
$string['datefrom']            = 'Desde';
$string['dateto']              = 'Hasta';
$string['daysinactive']    = 'Días sin actividad';
$string['daysinactive_desc']   = 'Días desde la última interacción';
$string['dedication']          = 'Tiempo de conexión';
$string['dedication_desc']     = 'Tiempo conectado al curso (bloque Dedicación)';
$string['details']             = 'Detalle';
$string['engdistribution']     = 'Distribución de participación';
$string['engdistribution_desc'] = 'Número de alumnos en cada cuartil de cobertura de recursos (basado en el % de recursos visitados).';
$string['filterbytype']        = 'Filtrar por tipo:';
$string['haventviewed']        = 'No han visto';
$string['haveviewed']          = 'Han visto';
$string['hidden']              = 'Oculto';
$string['lastaccess']          = 'Último acceso';
$string['lastaccess_desc']     = 'Fecha del último acceso registrado';
$string['lastactivity']        = 'Última actividad';
$string['lastactivity_desc']   = 'Interacción más reciente con un recurso';
$string['lastcoursevisit']     = 'Última visita al curso';
$string['lastcoursevisit_desc'] = 'Última vez que el alumno accedió al curso';
$string['modules'] = 'Módulos';
$string['moduletypesummary'] = 'Interacciones por tipo de recurso';
$string['moduletypesummary_desc'] = 'Vistas totales por tipo de recurso, normalizadas al tipo más visitado.';
$string['mostviewed']          = 'Más visitado';
$string['msgsent']             = 'Mensaje enviado';
$string['myactivity_desc']     = 'Tus interacciones diarias con los recursos del curso.';
$string['neveraccessed']   = 'Nunca';
$string['noactivitydata']      = 'Sin datos de actividad en este período.';
$string['nointeractions']      = 'No hay interacciones registradas en este período.';
$string['none']                = 'Ninguno';
$string['nostudents']          = 'No hay estudiantes matriculados en este curso.';
$string['notifyrisk']          = 'Mensaje a alumnos con baja actividad';
$string['notifyrisk_placeholder'] = 'Escribe tu mensaje aquí...';
$string['notviewed']           = 'sin ver';
$string['noviewsyet']          = 'Sin vistas aún.';
$string['pendingresources']    = 'Recursos que aún no has abierto';
$string['pendingresources_desc'] = 'Ábrelos para seguir el ritmo del curso.';
$string['plugindesc']          = 'Seguimiento de las interacciones de los estudiantes con los recursos y actividades del curso.';
$string['pluginname']          = 'Course Radar';

$string['resetfilter']         = 'Restablecer';
$string['resetsort']           = 'Volver a vista por secciones';
$string['resource']            = 'Recurso / Actividad';
$string['resourceactivity']    = 'Recursos y Actividades';
$string['resourceactivity_desc'] = 'Cobertura e interacciones por recurso, agrupadas por sección del curso. Haz clic en una columna para ordenar.';
$string['resourcesvisited']    = 'Recursos visitados';
$string['resourcesvisited_desc'] = 'Recursos distintos abiertos';
$string['riskscore']           = 'Puntuación';
$string['riskscore_desc']      = 'Puntuación de participación (0–100)';
$string['scatter_desc']        = 'Cada punto representa un alumno. Eje X: % de recursos visitados. Eje Y: puntuación de participación. Haz clic en un punto para abrir el perfil del alumno.';
$string['scatter_title']       = 'Comparativa de alumnos: recursos vs. participación';
$string['scatter_xaxis']       = '% Recursos visitados';
$string['scatter_yaxis']       = 'Puntuación de participación';
$string['scoredist_desc']       = 'Cada barra muestra cuántos alumnos tienen esa puntuación. La puntuación (0–100) combina tres factores: % de recursos visitados, días desde el último acceso y completación de actividades (si está activada). Un alumno que ha visitado pocos recursos pero entró ayer puntúa más alto que otro con la misma cobertura que lleva semanas sin aparecer.';
$string['scoredist_title']      = 'Distribución de la puntuación de participación';
$string['scorehelp_completion']    = '% de actividades con seguimiento completadas (solo se cuenta si la finalización de actividades está activada).';
$string['scorehelp_factors']       = 'La puntuación de participación (0–100) es una combinación ponderada de estos factores para cada alumno:';
$string['scorehelp_formula']       = 'Fórmula aplicada';
$string['scorehelp_formula_basic'] = 'puntuación = 0,50 × recursos + 0,50 × actividad reciente';
$string['scorehelp_formula_full']  = 'puntuación = 0,35 × recursos + 0,35 × actividad reciente + 0,30 × finalización';
$string['scorehelp_recency']       = 'Actividad reciente: 100 si entró hoy, descendiendo de forma lineal hasta 0 a los 30 días de inactividad (0 si el alumno nunca ha interactuado).';
$string['scorehelp_resources']     = '% de recursos distintos del curso que ha visitado el alumno.';
$string['scorehelp_title']         = '¿Cómo se calcula la puntuación de participación?';
$string['scope']               = 'OAuth scope';
$string['scope_desc']          = 'Opcional si se reutilizan las credenciales de Zoom UDIMA.';
$string['searchstudent']       = 'Buscar alumno...';
$string['sendmsg']             = 'Enviar mensaje';
$string['showhidden']          = 'Mostrar actividades ocultas';
$string['sortby']              = 'Haz clic para ordenar';
$string['student']             = 'Estudiante';
$string['studentcoverage_desc'] = '% de recursos del curso visitados';
$string['studentengagement']   = 'Actividad por estudiante';
$string['studentengagement_desc'] = 'Actividad individual de cada alumno. Haz clic en una columna para ordenar. Expande una fila para ver el detalle por recurso.';
$string['studentintro']        = 'Este es el resumen de tu actividad en este curso. Solo tú y tus profesores podéis verlo.';
$string['studentshowchart']    = 'Actividad a lo largo del tiempo';
$string['studentshowchart_desc'] = 'Mostrar el gráfico de interacciones diarias del alumno.';
$string['studentshowcomparison'] = 'Comparación con la clase';
$string['studentshowcomparison_desc'] = 'Mostrar las barras de «tú vs media de la clase» para las métricas activadas. La media es anónima.';
$string['studentshowcompletion'] = 'Finalización';
$string['studentshowcompletion_desc'] = 'Mostrar el porcentaje de actividades completadas (solo si el curso tiene seguimiento de finalización).';
$string['studentshowconexiones'] = 'Conexiones en directo / diferido';
$string['studentshowconexiones_desc'] = 'Mostrar el tiempo en Zoom en directo y las visualizaciones Vimeo en diferido del alumno.';
$string['studentshowcoverage'] = 'Recursos visitados';
$string['studentshowcoverage_desc'] = 'Mostrar el porcentaje de recursos del curso que ha abierto el alumno.';
$string['studentshowdaysinactive'] = 'Días sin actividad';
$string['studentshowdaysinactive_desc'] = 'Mostrar los días transcurridos desde la última interacción del alumno.';
$string['studentshowdedication'] = 'Tiempo de conexión';
$string['studentshowdedication_desc'] = 'Mostrar el tiempo de conexión al curso (requiere el bloque Dedicación).';
$string['studentshowpending']  = 'Recursos pendientes';
$string['studentshowpending_desc'] = 'Mostrar la lista de recursos que el alumno aún no ha abierto.';
$string['studentshowscore']    = 'Puntuación';
$string['studentshowscore_desc'] = 'Mostrar la puntuación de participación (0–100).';
$string['studentviewheading']  = 'Vista de estudiante';
$string['studentviewheading_desc'] = 'Elige qué ve un alumno matriculado en su resumen personal de Course Radar. El informe del profesor no cambia.';
$string['studentviews_desc']   = 'Accesos totales a todos los recursos';
$string['tab_overview']        = 'Resumen';
$string['tab_resources']       = 'Recursos';
$string['tab_students']        = 'Alumnos';
$string['times']               = 'vistas';
$string['tokenurl']            = 'URL del token OAuth';
$string['tokenurl_desc']       = 'Opcional si se reutilizan las credenciales de Zoom UDIMA.';
$string['topunseen']       = 'Recursos menos visitados';
$string['topunseeninfo']   = 'Recursos visibles con menor cobertura (excluidos los vistos por todos). Top 10 por filtro activo.';
$string['totalinteractions']   = 'Interacciones totales';
$string['totalresources']      = 'Recursos totales';

$string['totalviews']          = 'Vistas totales';
$string['totalviews_desc']     = 'Accesos totales de todos los alumnos';
$string['type']                = 'Tipo';
$string['uniquestudents']      = 'Estudiantes';
$string['uniquestudents_desc'] = 'Alumnos únicos / matriculados';

$string['viewprofile']         = 'Ver perfil';
$string['weeklyactivity'] = 'Actividad semanal';
$string['weeklyaggregated']    = 'Agrupado por semana (período > 90 días)';
$string['weekvspreview']       = 'vs. sem. anterior';
$string['you']                 = 'Tú';
